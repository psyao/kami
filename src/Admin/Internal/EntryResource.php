<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal;

use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea as TextareaInput;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource as FilamentResource;
use Filament\Resources\ResourceConfiguration;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Psyao\Kami\Admin\Internal\EntryResource\CreateEntry;
use Psyao\Kami\Admin\Internal\EntryResource\EditEntry;
use Psyao\Kami\Admin\Internal\EntryResource\ListEntries;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\Fields\Text;
use Psyao\Kami\Content\Fields\Textarea;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Content\Internal\EntryBuilder;
use Psyao\Kami\Sites\Internal\Site;
use UnitEnum;

/**
 * One Content Type's Entries in the default Locale, registered once per declared type under its handle (ADR 0011).
 *
 * @internal
 *
 * @extends FilamentResource<Entry>
 */
final class EntryResource extends FilamentResource
{
    #[\Override]
    protected static ?string $model = Entry::class;

    #[\Override]
    protected static ?string $configurationClass = ResourceConfiguration::class;

    #[\Override]
    protected static ?string $recordTitleAttribute = 'title';

    /** The Content Type of the configuration being served: its handle is the configuration's key. */
    public static function type(): ContentType
    {
        $handle = self::getConfiguration()?->getKey() ?? throw new LogicException('EntryResource is served through a configuration per Content Type.');

        return resolve(ContentTypes::class)->find($handle) ?? throw new LogicException("No Content Type [{$handle}] is declared in kami.types.");
    }

    /** `article` at `articles`, `news_item` at `news-items`. */
    public static function configure(ContentType $type): ResourceConfiguration
    {
        return self::make($type->handle())->slug(Str::plural(str_replace('_', '-', $type->handle())));
    }

    public static function getModelLabel(): string
    {
        return self::label('label', Str::headline(self::type()->handle()));
    }

    public static function getPluralModelLabel(): string
    {
        return self::label('plural', Str::plural(Str::headline(self::type()->handle())));
    }

    /** As translated: Filament would title-case it. */
    public static function getNavigationLabel(): string
    {
        return self::getPluralModelLabel();
    }

    /** The policy hears the type's handle when there is no record, so each type has its own Permissions (ADR 0014). */
    public static function getAuthorizationResponse(string | UnitEnum $action, ?Model $record = null): Response
    {
        $ability = $action instanceof UnitEnum ? $action->name : $action;

        return Gate::forUser(Filament::auth()->user())->inspect($ability, $record ?? [Entry::class, self::type()->handle()]);
    }

    public static function getEloquentQuery(): Builder
    {
        return Entry::query()->withUnpublished()->ofType(self::type()->handle());
    }

    public static function form(Schema $schema): Schema
    {
        $timezone = Site::current()->timezone;

        return $schema->columns(1)->components([
            TextInput::make('title')->label(__('kami::admin.fields.title')),
            TextInput::make('slug')->label(__('kami::admin.fields.slug'))->helperText(__('kami::admin.fields.slug_help')),
            ...array_map(self::component(...), self::type()->fields()),
            // The date decides when a published Translation goes live, so it takes `publish`; a disabled field is not saved (ADR 0019).
            DateTimePicker::make('published_at')->label(__('kami::admin.fields.published_at'))->timezone($timezone)->helperText(__('kami::admin.fields.published_at_help', ['timezone' => $timezone]))
                ->disabled(fn (?Entry $record): bool => ! self::can('publish', $record)),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('kami::admin.fields.title'))
                    ->searchable(query: fn (EntryBuilder $query, string $search): EntryBuilder => $query->whereHas('translations', fn (Builder $translations) => $translations
                        ->whereIn('locale', [Site::current()->defaultLocale(), '*'])
                        ->where('title', 'like', "%{$search}%"))),
                TextColumn::make('state')
                    ->label(__('kami::admin.state.label'))
                    ->badge()
                    ->state(fn (Entry $record): string => __('kami::admin.state.' . self::state($record))),
                TextColumn::make('published_at')
                    ->label(__('kami::admin.fields.published_at'))
                    ->dateTime()
                    ->timezone(Site::current()->timezone),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEntries::route('/'),
            'create' => CreateEntry::route('/create'),
            'edit' => EditEntry::route('/{record}/edit'),
        ];
    }

    /**
     * Saves through `SaveEntry`, the one way content is written (ADR 0011), with its errors on the form's fields.
     *
     * @param  array<string, mixed>  $data
     */
    public static function save(array $data, ?Entry $entry = null, ?string $status = null): Entry
    {
        try {
            return resolve(SaveEntry::class)->handle(self::type()->handle(), [...$data, ...array_filter(['status' => $status])], $entry, actor: Filament::auth()->user());
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $key => $messages) {
                $errors["data.{$key}"] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }

    /** Draft, Scheduled or Published, as visitors see the Translation now (ADR 0019). */
    private static function state(Entry $entry): string
    {
        return match (true) {
            $entry->status !== 'published' => 'draft',
            $entry->isVisible() => 'published',
            default => 'scheduled',
        };
    }

    private static function component(Field $field): Component
    {
        $component = match (true) {
            $field instanceof Text => TextInput::make($field->handle),
            $field instanceof Textarea => TextareaInput::make($field->handle),
            default => throw new LogicException('No admin component for the Field type ' . $field::class . '.'),
        };

        return $component
            ->label(self::label("fields.{$field->handle}.label", Str::headline($field->handle)))
            ->helperText(self::label("fields.{$field->handle}.help", '') ?: null)
            ->default($field->defaultValue());
    }

    // ponytail: reads kami-types directly; #100's public label read replaces it.
    private static function label(string $key, string $fallback): string
    {
        $key = 'kami-types.' . self::type()->handle() . ".{$key}";

        return Lang::has($key) ? (string) __($key) : $fallback;
    }
}
