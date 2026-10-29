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
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
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
use Psyao\Kami\Content\Internal\Translation;
use Psyao\Kami\Content\Internal\Visibility;
use Psyao\Kami\Sites\Internal\Site;
use UnitEnum;

/**
 * One Content Type's Entries, edited one Locale at a time, registered once per declared type under its handle (ADR 0011).
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
        return self::type()->label();
    }

    public static function getPluralModelLabel(): string
    {
        return self::type()->pluralLabel();
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
        $livewire = $schema->getLivewire();
        // The Locale of the values typed, so assistive technology reads them in their language (WCAG 3.1.2).
        $lang = self::locales() === [] ? [] : ['lang' => $livewire instanceof EditEntry ? $livewire->locale : Site::current()->defaultLocale()];

        return $schema->columns(1)->components([
            TextInput::make('title')->label(__('kami::admin.fields.title'))->extraInputAttributes($lang),
            TextInput::make('slug')->label(__('kami::admin.fields.slug'))->helperText(__('kami::admin.fields.slug_help'))->extraInputAttributes($lang),
            ...array_map(fn (Field $field): Component => self::component($field, $lang), self::type()->fields()),
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
                ...array_map(self::stateColumn(...), self::locales() ?: ['*']),
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
            // The default Locale's at `/edit`, another's at `/edit/en`, as on the front.
            'edit' => EditEntry::route('/{record}/edit/{locale?}'),
        ];
    }

    /**
     * The Locales a Translation of the type may have, each with its own edit page; none for an untranslatable type, whose `'*'` Translation answers in every Locale.
     *
     * @return list<string>
     */
    public static function locales(): array
    {
        return self::type()->isTranslatable() ? Site::current()->locales : [];
    }

    /**
     * Saves through `SaveEntry`, the one way content is written (ADR 0011), with its errors on the form's fields.
     *
     * @param  array<string, mixed>  $data
     */
    public static function save(array $data, ?Entry $entry = null, ?string $status = null, ?string $locale = null): Entry
    {
        try {
            return resolve(SaveEntry::class)->handle(self::type()->handle(), [...$data, ...array_filter(['status' => $status])], $entry, $locale, Filament::auth()->user());
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(Arr::prependKeysWith($exception->errors(), 'data.'));
        }
    }

    /** One Locale's state, or the `'*'` Translation's as `state` for an untranslatable type. */
    private static function stateColumn(string $locale): TextColumn
    {
        return TextColumn::make($locale === '*' ? 'state' : "state_{$locale}")
            ->label($locale === '*' ? __('kami::admin.state.label') : __('kami::admin.state.label_in', ['locale' => $locale]))
            ->badge()
            ->state(fn (Entry $record): string => __('kami::admin.state.' . self::state($record->translations->firstWhere('locale', $locale))));
    }

    /** Draft, Scheduled or Published, as visitors see the Translation now (ADR 0019), or missing. */
    private static function state(?Translation $translation): string
    {
        return match (true) {
            ! $translation instanceof Translation => 'missing',
            $translation->status !== Translation::PUBLISHED => 'draft',
            Visibility::isVisible($translation) => 'published',
            default => 'scheduled',
        };
    }

    /** @param  array<string, string>  $lang */
    private static function component(Field $field, array $lang): Component
    {
        // ponytail: one arm per Field type, Kami's two only; M3's `fieldComponents()` registry replaces it.
        $component = match (true) {
            $field instanceof Text => TextInput::make($field->handle),
            $field instanceof Textarea => TextareaInput::make($field->handle),
            default => throw new LogicException('No admin component for the Field type ' . $field::class . '.'),
        };

        $component
            ->label(self::type()->fieldLabel($field->handle))
            ->helperText(self::type()->fieldHelp($field->handle))
            ->default($field->defaultValue());

        if (! self::type()->isTranslatable() || self::type()->isTranslatable($field)) {
            return $component->extraInputAttributes($lang);
        }

        // One value for every Locale: changing it changes visible Translations too, which takes `publish` (ADR 0019).
        return $component
            ->hint(__('kami::admin.fields.shared'))
            ->disabled(fn (?Entry $record): bool => $record instanceof Entry && ! self::can('publish', $record)
                && $record->translations->contains(fn (Translation $translation): bool => Visibility::isVisible($translation)));
    }
}
