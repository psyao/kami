<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource as FilamentResource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Psyao\Kami\Admin\Internal\RedirectResource\ManageRedirects;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Redirects\Actions\DeleteRedirect;
use Psyao\Kami\Redirects\Actions\SaveRedirect;
use Psyao\Kami\Redirects\Redirect;
use Psyao\Kami\Sites\Internal\Site;
use UnitEnum;

/**
 * The current Site's Redirects, Kami's own included, on one page, written through `SaveRedirect` and `DeleteRedirect` only (ADR 0008).
 *
 * @internal
 *
 * @extends FilamentResource<Redirect>
 */
final class RedirectResource extends FilamentResource
{
    #[\Override]
    protected static ?string $model = Redirect::class;

    #[\Override]
    protected static bool $hasTitleCaseModelLabel = false;

    #[\Override]
    protected static ?string $slug = 'redirects';

    public static function getModelLabel(): string
    {
        return __('kami::admin.redirects.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('kami::admin.redirects.plural_label');
    }

    /** As translated: Filament would title-case it. */
    public static function getNavigationLabel(): string
    {
        return self::getPluralModelLabel();
    }

    /** One Permission for every read and write (ADR 0014). */
    public static function getAuthorizationResponse(string | UnitEnum $action, ?Model $record = null): Response
    {
        return Gate::forUser(Filament::auth()->user())->inspect('kami.redirects.manage');
    }

    public static function getEloquentQuery(): Builder
    {
        return Redirect::query()->where('site', Site::current()->handle);
    }

    public static function form(Schema $schema): Schema
    {
        $locales = Site::current()->locales;
        // The source and Locale are what `SaveRedirect` saves by: changed on an edit, they would leave the old Redirect behind.
        $kept = fn (?Redirect $record): bool => $record instanceof Redirect;
        $gone = fn (Get $get): bool => in_array($get('status'), [410, '410'], true);
        $shownFor = fn (string $kind): Closure => fn (Get $get): bool => ! $gone($get) && $get('kind') === $kind;

        return $schema->columns(1)->components([
            TextInput::make('source')->label(__('kami::admin.redirects.fields.source'))
                ->helperText(fn (?Redirect $record): string => __($record instanceof Redirect ? 'kami::admin.redirects.fields.source_kept' : 'kami::admin.redirects.fields.source_help'))
                ->required()->disabled($kept),
            Select::make('locale')->label(__('kami::admin.redirects.fields.locale'))
                ->options(array_combine($locales, array_map(strtoupper(...), $locales)))
                ->default(Site::current()->defaultLocale())->selectablePlaceholder(false)
                ->hidden(count($locales) === 1)->disabled($kept),
            ToggleButtons::make('status')->label(__('kami::admin.redirects.fields.status'))
                ->options(self::statuses())->default(301)->inline()->live()->required(),
            ToggleButtons::make('kind')->label(__('kami::admin.redirects.fields.kind'))
                ->options(['entry' => __('kami::admin.redirects.fields.entry'), 'url' => __('kami::admin.redirects.fields.address')])
                ->default('entry')->inline()->live()
                ->hidden($gone),
            Select::make('entry')->label(__('kami::admin.redirects.fields.entry'))
                ->searchable()
                ->getSearchResultsUsing(fn (string $search, mixed $livewire): array => EntryResource::linkableTitles($search, $livewire))
                ->getOptionLabelUsing(fn (mixed $value, mixed $livewire): ?string => is_string($value) ? EntryResource::pickerTitles([$value], $livewire)[$value] ?? null : null)
                ->visible($shownFor('entry')),
            TextInput::make('target')->label(__('kami::admin.redirects.fields.address'))
                ->maxLength(2048)
                ->visible($shownFor('url')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('path')->label(__('kami::admin.redirects.columns.source'))
                    ->formatStateUsing(fn (string $state): string => "/{$state}")
                    // Stored without its leading slash, lowercase.
                    ->searchable(query: self::whereSource(...))
                    ->sortable(),
                TextColumn::make('locale')->label(__('kami::admin.redirects.columns.locale'))
                    ->formatStateUsing(fn (string $state): string => strtoupper($state))
                    ->visible(count(Site::current()->locales) > 1),
                TextColumn::make('status')->label(__('kami::admin.redirects.columns.status'))
                    ->formatStateUsing(fn (int $state): string => self::statuses()[$state] ?? (string) $state),
                TextColumn::make('target')->label(__('kami::admin.redirects.columns.target'))
                    ->state(fn (Redirect $record, ManageRedirects $livewire): ?string => $livewire->target($record))
                    // Each cell is a button opening the row's form: an empty one would have no name (WCAG 4.1.2). Not `placeholder()`, whose grey fails contrast (WCAG 1.4.3).
                    ->default(__('kami::admin.redirects.no_target')),
                // Null for the Redirects Kami wrote before 0.10.0; formatted here, as `dateTime()` would parse the default.
                TextColumn::make('updated_at')->label(__('kami::admin.redirects.columns.date'))
                    ->state(fn (Redirect $record, TextColumn $column): string => $record->updated_at?->setTimezone(Site::current()->timezone)->translatedFormat($column->getTable()->getDefaultDateTimeDisplayFormat()) ?? __('kami::admin.redirects.no_date'))
                    ->sortable()->visibleFrom('md'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('kami::admin.redirects.columns.status'))->options(self::statuses()),
            ])
            ->defaultSort('path')
            ->recordActions([
                EditAction::make()
                    ->fillForm(fn (Redirect $record): array => [
                        'source' => "/{$record->path}",
                        'locale' => $record->locale,
                        'status' => $record->status,
                        'kind' => $record->target === null ? 'entry' : 'url',
                        'entry' => $record->entry_id,
                        'target' => $record->target,
                    ])
                    ->using(fn (Redirect $record, array $data, Action $action): Redirect => self::save($data, $action, $record)),
                DeleteAction::make()
                    ->modalDescription(__('kami::admin.redirects.delete'))
                    ->using(function (Redirect $record): bool {
                        resolve(DeleteRedirect::class)->handle($record, Filament::auth()->user());

                        return true;
                    }),
            ]);
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()->using(fn (array $data, Action $action): Redirect => self::save($data, $action));
    }

    public static function getPages(): array
    {
        return ['index' => ManageRedirects::route('/')];
    }

    /**
     * @param  Builder<Redirect>  $query
     * @return Builder<Redirect>
     */
    private static function whereSource(Builder $query, string $search): Builder
    {
        // `!` escapes LIKE's wildcards, so `attachment_id` matches its `_` alone; query values are stored percent-encoded and searched so.
        return $query->whereRaw("path like ? escape '!'", ['%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], ltrim(mb_strtolower($search), '/')) . '%']);
    }

    /** @return array<int, string> */
    private static function statuses(): array
    {
        return [301 => __('kami::admin.redirects.statuses.301'), 302 => __('kami::admin.redirects.statuses.302'), 410 => __('kami::admin.redirects.statuses.410')];
    }

    /**
     * Through `SaveRedirect`, the chosen target only; an edit keeps the stored source and Locale. Its refusals land on the modal's inputs.
     *
     * @param  array<mixed>  $data  The modal's state, as Filament hands it.
     */
    private static function save(array $data, Action $action, ?Redirect $redirect = null): Redirect
    {
        $status = is_numeric($data['status'] ?? null) ? (int) $data['status'] : 0;
        $kind = $status === 410 ? null : $data['kind'] ?? 'entry';
        [$source, $locale] = $redirect instanceof Redirect
            ? ["/{$redirect->path}", $redirect->locale]
            : [is_string($data['source'] ?? null) ? $data['source'] : '', is_string($data['locale'] ?? null) ? $data['locale'] : null];

        try {
            $target = match ($kind) {
                // An id no Entry holds, hand-posted, is refused as SaveRedirect refuses one in the Trash.
                'entry' => is_string($data['entry'] ?? null) ? Entry::query()->withUnpublished()->withTrashed()->find($data['entry']) ?? throw ValidationException::withMessages(['target' => __('kami::admin.redirects.entry')]) : null,
                'url' => is_string($data['target'] ?? null) ? $data['target'] : null,
                default => null,
            };

            return resolve(SaveRedirect::class)->handle($source, $status, $target, $locale, Filament::auth()->user());
        } catch (ValidationException $exception) {
            $errors = [];

            foreach ($exception->errors() as $key => $messages) {
                $errors["mountedActions.{$action->getNestingIndex()}.data." . ($key === 'target' ? ($kind === 'url' ? 'target' : 'entry') : $key)] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }
}
