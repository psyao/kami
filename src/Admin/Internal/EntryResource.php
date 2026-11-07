<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use DateTimeInterface;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\ReplicateAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field as FormField;
use Filament\Forms\Components\Repeater as RepeaterInput;
use Filament\Forms\Components\Select as SelectInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource as FilamentResource;
use Filament\Resources\ResourceConfiguration;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section as FilamentSection;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab as FilamentTab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component as LivewireComponent;
use LogicException;
use Psyao\Kami\Admin\EntryForm;
use Psyao\Kami\Admin\Internal\EntryResource\CreateEntry;
use Psyao\Kami\Admin\Internal\EntryResource\EditEntry;
use Psyao\Kami\Admin\Internal\EntryResource\ListEntries;
use Psyao\Kami\Admin\KamiPlugin;
use Psyao\Kami\Content\Actions\DeleteEntry;
use Psyao\Kami\Content\Actions\DuplicateEntry;
use Psyao\Kami\Content\Actions\RestoreEntry;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Actions\TrashEntry;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\Fields\Group;
use Psyao\Kami\Content\Fields\Media as MediaField;
use Psyao\Kami\Content\Fields\RelationField;
use Psyao\Kami\Content\Fields\RichText;
use Psyao\Kami\Content\Fields\Select;
use Psyao\Kami\Content\Fields\StructuredField;
use Psyao\Kami\Content\GlobalSet;
use Psyao\Kami\Content\Internal\Ancestors;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Content\Internal\EntryBuilder;
use Psyao\Kami\Content\Internal\EntrySnapshot;
use Psyao\Kami\Content\Internal\EntryTitles;
use Psyao\Kami\Content\Internal\References;
use Psyao\Kami\Content\Internal\Translation;
use Psyao\Kami\Content\Internal\Visibility;
use Psyao\Kami\Content\Layout;
use Psyao\Kami\Content\RichText\Document;
use Psyao\Kami\Content\Singleton;
use Psyao\Kami\Content\Tab;
use Psyao\Kami\Content\Taxonomy;
use Psyao\Kami\Media\Media;
use Psyao\Kami\Routing\Internal\Path;
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

    /** `article` at `articles`, `news_item` at `news-items`; a Singleton, being one thing, at `home`. */
    public static function configure(ContentType $type): ResourceConfiguration
    {
        $slug = str_replace('_', '-', $type->handle());

        return self::make($type->handle())->slug($type instanceof Singleton ? $slug : Str::plural($slug));
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
        return self::type() instanceof Singleton ? self::getModelLabel() : self::getPluralModelLabel();
    }

    /** The policy hears the type's handle when there is no record, so each type has its own Permissions (ADR 0014). */
    public static function getAuthorizationResponse(string | UnitEnum $action, ?Model $record = null): Response
    {
        $ability = $action instanceof UnitEnum ? $action->name : $action;

        return Gate::forUser(Filament::auth()->user())->inspect($ability, $record ?? [Entry::class, self::type()->handle()]);
    }

    /**
     * The handles of the types the User may list, in declaration order: what search and the dashboard reach.
     *
     * @return list<string>
     */
    public static function accessibleTypes(): array
    {
        $types = [];

        foreach (Filament::getCurrentOrDefaultPanel()?->getResourceConfigurations() ?? [] as $configuration) {
            if ($configuration->getResource() === self::class && self::withConfiguration($configuration->getKey(), self::canAccess(...))) {
                $types[] = $configuration->getKey();
            }
        }

        return $types;
    }

    /** A Global Set is always published, so its one page takes `update` and `publish` (ADR 0019): no navigation item leads to a 403. */
    public static function canAccess(): bool
    {
        $type = self::type();

        return parent::canAccess() && (! $type instanceof GlobalSet || Gate::forUser(Filament::auth()->user())->check(["kami.entries.{$type->handle()}.update", "kami.entries.{$type->handle()}.publish"]));
    }

    public static function getEloquentQuery(): Builder
    {
        return Entry::query()->withUnpublished()->ofType(self::type()->handle());
    }

    public static function form(Schema $schema): Schema
    {
        $livewire = $schema->getLivewire();
        $form = self::plugin()->entryForm(self::type(), $livewire);
        $components = $form->components();

        // The generated form is the list of what is stored, so a site's form neither adds a value with no column nor hides one (ADR 0007).
        if ($form::class !== EntryForm::class) {
            $expected = self::formFieldPaths(self::generatedForm($livewire), $livewire);
            $built = self::formFieldPaths($components, $livewire);

            if (($added = array_diff($built, $expected)) !== []) {
                throw new LogicException($form::class . ' adds [' . implode(', ', $added) . '], which ' . self::type()::class . ' does not declare.');
            }

            if (($missing = array_diff($expected, $built)) !== []) {
                throw new LogicException($form::class . ' leaves out [' . implode(', ', $missing) . ']; hide one with ->hidden() to keep it out of sight.');
            }
        }

        // Last: walking the components above attaches them to a schema of its own.
        return $schema->columns(1)->components($components);
    }

    /**
     * The form the declaration gives.
     *
     * @return list<Component>
     */
    public static function generatedForm(HasSchemas & LivewireComponent $livewire): array
    {
        $timezone = Site::current()->timezone;
        // The Locale of the values typed, so assistive technology reads them in their language (WCAG 3.1.2).
        $locale = $livewire instanceof EditEntry ? $livewire->locale : Site::current()->defaultLocale();
        $lang = self::locales() === [] ? [] : ['lang' => $locale];
        // Read from the page, not the schema's record: saving replaces it, and the first save stores a slug. No slug stored, no Path can move.
        $hasStoredSlug = fn (): bool => $livewire instanceof EditEntry && filled($livewire->getRecord()->slug);
        $hasSlug = self::type()->hasSlug();
        // Its values simply apply: `SaveEntry` publishes it and titles it with its label.
        $hasPublication = ! self::type() instanceof GlobalSet;

        return [
            ...($hasPublication ? [TextInput::make('title')->label(__('kami::admin.fields.title'))->extraInputAttributes($lang)
                ->live(onBlur: true, condition: fn (): bool => $hasSlug && ! $hasStoredSlug())
                // The hook also runs on a save's deferred update, hence the check. Blank or the previous title's slug: not typed by hand.
                ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state) use ($hasSlug, $hasStoredSlug, $locale): void {
                    if ($hasSlug && ! $hasStoredSlug() && (blank($get('slug')) || $get('slug') === Path::slugFrom($old ?? '', $locale))) {
                        $set('slug', Path::slugFrom($state ?? '', $locale));
                    }
                })] : []),
            ...($hasSlug ? [TextInput::make('slug')->label(__('kami::admin.fields.slug'))->extraInputAttributes($lang)
                // ponytail: a warning, as the old address answers 404; M7's redirect on slug change removes it.
                ->helperText(fn (): string => __($hasStoredSlug() ? 'kami::admin.fields.slug_moves' : 'kami::admin.fields.slug_help'))] : []),
            ...(self::type()->isHierarchical() ? [self::parentInput()] : []),
            ...self::components(self::type()->fields(), $lang, collect(self::type()->flatFields())->keyBy('handle')),
            // The date decides when a published Translation goes live, so it takes `publish` (ADR 0019); `save()` holds the rule, this shows it.
            ...($hasPublication ? [DateTimePicker::make('published_at')->label(__('kami::admin.fields.published_at'))->timezone($timezone)->helperText(__('kami::admin.fields.published_at_help', ['timezone' => $timezone]))
                // Live, so Publish reads Schedule as soon as a date to come is chosen.
                ->live()
                ->disabled(fn (?Entry $record): bool => ! self::can('publish', $record))] : []),
        ];
    }

    /**
     * Every component holding a value, by its path: `client`, a Group's `address` and `address.city`. A Repeater's rows are its own: they exist only once the form has state.
     *
     * @param  array<Component|Action|ActionGroup>  $components
     * @return array<string, Component>
     */
    public static function inputs(array $components, HasSchemas & LivewireComponent $livewire): array
    {
        // With the page's record, as the form has it: a site's closure may read it.
        return self::inputsOf(Schema::make($livewire)->record($livewire instanceof EditEntry ? $livewire->getRecord() : null)->components($components));
    }

    /**
     * @param  array<Component|Action|ActionGroup>  $components
     * @return list<string>
     */
    private static function formFieldPaths(array $components, HasSchemas & LivewireComponent $livewire): array
    {
        return array_keys(array_filter(self::inputs($components, $livewire), fn (Component $input): bool => $input instanceof FormField));
    }

    /** @return array<string, Component> */
    private static function inputsOf(Schema $schema, string $prefix = ''): array
    {
        $inputs = [];

        foreach ($schema->getComponents(withActions: false, withHidden: true) as $component) {
            if (! $component instanceof Component) {
                continue;
            }

            $own = $component->getStatePath(false);

            if (filled($own)) {
                $inputs[$prefix . $own] = $component;
            }

            if (! $component instanceof FormField) {
                foreach ($component->getChildSchemas(withHidden: true) as $child) {
                    $inputs = [...$inputs, ...self::inputsOf($child, filled($own) ? "{$prefix}{$own}." : $prefix)];
                }
            }
        }

        return $inputs;
    }

    public static function table(Table $table): Table
    {
        // A title lives on its Translation, out of reach of Filament's `recordTitleAttribute`: reorder buttons and modals would all read the type's label.
        $table->recordTitle(fn (Entry $record): string => self::firstTitle($record) ?? __('kami::admin.fields.untitled'));

        if (self::type()->isOrderable()) {
            // Paginated: a whole type's rows could exceed shared hosting's memory limit; a row moves within its page (ADR 0023).
            $table->reorderable('position')->paginatedWhileReordering()->authorizeReorder(fn (): bool => self::can('reorder'))
                ->defaultSort(fn (EntryBuilder $query): Builder => $query->ordered());
        }

        if (self::type()->isHierarchical()) {
            $table->modifyQueryUsing(fn (EntryBuilder $query, ListEntries $livewire): EntryBuilder => $query->where('parent_id', $livewire->parent));
        }

        $entryTable = self::plugin()->entryTable(self::type());

        return $table
            ->columns($entryTable->columns())
            ->filters($entryTable->filters())
            ->recordActions($entryTable->recordActions())
            ->toolbarActions($entryTable->toolbarActions())
            // A bulk action reaches one page per request: at most the largest page size, 50 rows, within shared hosting's time limit.
            ->paginationPageOptions([5, 10, 25, 50])
            ->selectCurrentPageOnly();
    }

    /** @return list<TextColumn> */
    public static function columns(): array
    {
        return [
            TextColumn::make('title')
                ->label(__('kami::admin.fields.title'))
                // Every cell links to the record: an empty one would be a link with no name (WCAG 2.4.4). Not `placeholder()`, whose grey fails contrast (WCAG 1.4.3).
                ->state(fn (Entry $record): ?string => self::firstTitle($record))
                ->default(__('kami::admin.fields.untitled'))
                // Any Locale's: a row may show a title from any of them.
                ->searchable(query: fn (EntryBuilder $query, string $search): EntryBuilder => $query->whereHas('translations', fn (Builder $translations) => $translations
                    ->whereLike('title', "%{$search}%"))),
            ...array_map(self::stateColumn(...), self::locales() ?: ['*']),
            self::userColumn('creator', __('kami::admin.users.creator')),
            self::userColumn('lastModifier', __('kami::admin.users.last_modifier')),
        ];
    }

    /** The first title in the Site's Locale order, the default Locale's first; a Locale no longer declared comes last. */
    private static function firstTitle(Entry $entry): ?string
    {
        $order = array_flip(Site::current()->locales);

        return $entry->translations->whereNotNull('title')->sortBy(fn (Translation $translation): int => $order[$translation->locale] ?? count($order))->first()?->title;
    }

    /** @return list<BaseFilter> */
    public static function filters(): array
    {
        return [TrashedFilter::make()
            ->label(__('kami::admin.trash.filter'))
            ->placeholder(__('kami::admin.trash.without'))
            ->trueLabel(__('kami::admin.trash.with'))
            ->falseLabel(__('kami::admin.trash.only'))];
    }

    /** @return list<Action> */
    public static function recordActions(): array
    {
        $trashed = fn (Entry $record): bool => $record->trashed();

        return [
            ...(self::type()->isHierarchical() ? [Action::make('children')->label(__('kami::admin.actions.children'))->url(fn (Entry $record): string => self::getUrl(parameters: ['parent' => $record->id]))->hidden($trashed)] : []),
            // A trashed Entry has no edit page: nothing changes while it waits.
            EditAction::make()->hidden($trashed),
            // The copy opens at once: duplicating is where its editing starts.
            ReplicateAction::make()
                ->label(__('kami::admin.actions.duplicate'))
                ->modalHeading(fn (ReplicateAction $action): string => __('kami::admin.duplicate.heading', ['title' => $action->getRecordTitle()]))
                ->modalDescription(__('kami::admin.duplicate.description'))
                ->modalSubmitActionLabel(__('kami::admin.actions.duplicate'))
                ->successNotificationTitle(__('kami::admin.duplicate.done'))
                ->hidden($trashed)
                // No refusal to show: a Singleton or a Global Set, the only ones refused, has no list.
                ->action(function (Entry $record, ReplicateAction $action): void {
                    $copy = resolve(DuplicateEntry::class)->handle($record, Filament::auth()->user());
                    $action->success();
                    $action->redirect(self::editUrl($copy, null));
                }),
            DeleteAction::make()
                ->label(__('kami::admin.actions.trash'))
                ->modalHeading(fn (DeleteAction $action): string => __('kami::admin.trash.heading', ['title' => $action->getRecordTitle()]))
                ->modalDescription(function (Entry $record): string {
                    $sources = EntryTitles::list(References::sources($record->id));

                    return $sources === '' ? __('kami::admin.trash.description') : __('kami::admin.trash.used_by', ['entries' => $sources]);
                })
                ->modalSubmitActionLabel(__('kami::admin.actions.trash'))
                ->successNotificationTitle(__('kami::admin.trash.done'))
                ->using(fn (Entry $record): bool => self::onNotification(fn () => resolve(TrashEntry::class)->handle($record, Filament::auth()->user()))),
            RestoreAction::make()
                ->using(fn (Entry $record): bool => self::onNotification(fn () => resolve(RestoreEntry::class)->handle($record, Filament::auth()->user()))),
            // Its addresses go to an Entry of the same type outside the Trash, or answer 410 (ADR 0008); `DeleteEntry` itself takes any Entry.
            ForceDeleteAction::make()
                ->label(__('kami::admin.actions.delete'))
                ->modalHeading(fn (ForceDeleteAction $action): string => __('kami::admin.delete.heading', ['title' => $action->getRecordTitle()]))
                ->modalDescription(__('kami::admin.delete.description'))
                ->schema([
                    self::sameTypeInput('replacement')
                        ->label(__('kami::admin.delete.replacement'))
                        ->placeholder(__('kami::admin.delete.gone')),
                ])
                ->using(fn (Entry $record, array $data): bool => self::onNotification(function () use ($record, $data): void {
                    $id = is_string($data['replacement'] ?? null) ? $data['replacement'] : null;
                    // Trashed or deleted since it was picked: refused rather than read as a 410.
                    $replacement = $id === null ? null : self::getEloquentQuery()->find($id) ?? throw ValidationException::withMessages(['replacement' => __('validation.exists', ['attribute' => __('kami::admin.delete.replacement')])]);

                    resolve(DeleteEntry::class)->handle($record, $replacement, Filament::auth()->user());
                })),
        ];
    }

    /** Runs a core action; its refusal shows on a notification, and the Filament action fails with no other message. */
    public static function onNotification(Closure $call): bool
    {
        try {
            $call();

            return true;
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title($exception->validator->errors()->first())->send();

            return false;
        }
    }

    /**
     * Trash, Restore, Force delete, Publish and Unpublish on the selected rows: each row authorised on its own, then written by a core action; a refused row stops nothing, the report counts it with its reason.
     *
     * @return list<BulkActionGroup>
     */
    public static function toolbarActions(): array
    {
        $actor = fn (): ?Authenticatable => Filament::auth()->user();
        $trashed = fn (Entry $record): ?string => $record->trashed() ? (string) __('kami::admin.bulk.trashed') : null;
        $notTrashed = fn (Entry $record): ?string => $record->trashed() ? null : (string) __('kami::admin.bulk.not_trashed');

        return [BulkActionGroup::make([
            self::rowByRow(
                DeleteBulkAction::make()
                    ->label(__('kami::admin.actions.trash'))
                    ->modalHeading(__('kami::admin.bulk.trash_heading'))
                    ->modalDescription(__('kami::admin.bulk.trash_description'))
                    ->modalSubmitActionLabel(__('kami::admin.actions.trash'))
                    ->successNotificationTitle(__('kami::admin.bulk.trash_done'))
                    ->authorizeIndividualRecords(),
                $trashed,
                fn (Entry $record) => resolve(TrashEntry::class)->handle($record, $actor()),
            ),
            self::rowByRow(RestoreBulkAction::make()->authorizeIndividualRecords(), $notTrashed, fn (Entry $record) => resolve(RestoreEntry::class)->handle($record, $actor())),
            // No replacement row by row: every address answers 410 (ADR 0008); the row action offers one.
            self::rowByRow(
                ForceDeleteBulkAction::make()
                    ->label(__('kami::admin.actions.delete_selected'))
                    ->modalHeading(__('kami::admin.bulk.delete_heading'))
                    ->modalDescription(__('kami::admin.bulk.delete_description'))
                    ->authorizeIndividualRecords(),
                $notTrashed,
                fn (Entry $record) => resolve(DeleteEntry::class)->handle($record, actor: $actor()),
            ),
            self::statusBulkAction('publish', Translation::PUBLISHED, $trashed, $actor),
            self::statusBulkAction('unpublish', Translation::DRAFT, $trashed, $actor),
        ])];
    }

    /**
     * Publish or Unpublish one Locale's Translations, the Locale asked on a translatable type, under `update` and `publish` per row (ADR 0019).
     *
     * @param  Closure(Entry): ?string  $trashed
     * @param  Closure(): ?Authenticatable  $actor
     */
    private static function statusBulkAction(string $name, string $status, Closure $trashed, Closure $actor): BulkAction
    {
        $locales = self::locales();
        $locale = fn (array $data): ?string => is_string($data['locale'] ?? null) ? $data['locale'] : null;

        $action = BulkAction::make($name)
            ->label(__("kami::admin.actions.{$name}"))
            ->requiresConfirmation()
            ->modalHeading(__("kami::admin.bulk.{$name}_heading"))
            ->schema($locales === [] ? [] : [SelectInput::make('locale')
                ->label(__('kami::admin.search.locale'))
                ->options(array_combine($locales, array_map(strtoupper(...), $locales)))
                ->default(Site::current()->defaultLocale())
                ->required()])
            ->authorize(fn (): bool => self::can('publish'))
            // Hidden where every row would be refused, the Trash alone shown, as Filament's `DeleteBulkAction` is; a trashed row in a mixed selection is refused.
            ->hidden(fn (HasTable $livewire): bool => filled($trash = $livewire->getTableFilterState(TrashedFilter::class)['value'] ?? null) && ! $trash)
            ->authorizeIndividualRecords(fn (Entry $record): bool => self::can('update', $record) && self::can('publish', $record))
            ->successNotificationTitle(__("kami::admin.bulk.{$name}_done"))
            ->deselectRecordsAfterCompletion();
        /** @var list<string> $written The rows this run wrote: Filament calls `after()` once every row's `action()` has run. */
        $written = [];

        if ($status === Translation::PUBLISHED) {
            $action->after(function (array $data) use (&$written, $locale): void {
                self::remindAlt($written, $locale($data));
            });
        }

        return self::rowByRow(
            $action,
            function (Entry $record, array $data) use ($status, $trashed, $locale): ?string {
                $translation = $record->translations->whereIn('locale', [$locale($data) ?? '*', '*'])->first();

                return $trashed($record) ?? match (true) {
                    $translation === null => (string) __('kami::admin.bulk.no_translation', ['locale' => strtoupper((string) $locale($data))]),
                    $translation->status === $status => (string) __("kami::admin.bulk.already_{$status}"),
                    default => null,
                };
            },
            function (Entry $record, array $data) use ($status, $locale, $actor, &$written): void {
                resolve(SaveEntry::class)->handle(self::type()->handle(), ['status' => $status], $record, $locale($data), $actor());
                $written[] = $record->id;
            },
        );
    }

    /**
     * Writes each row the User may act on, unless `$refuse` gives a reason to leave it; a core action's refusal counts under its own. Authorising each row is the caller's: `authorizeIndividualRecords()`.
     *
     * @param  Closure(Entry, array<mixed>): ?string  $refuse  The reason a row is left as it is, such as being in that state already.
     * @param  Closure(Entry, array<mixed>): mixed  $write
     */
    private static function rowByRow(BulkAction $action, Closure $refuse, Closure $write): BulkAction
    {
        return $action
            ->failureNotificationTitle(fn (int $successCount, int $totalCount): string => (string) __('kami::admin.bulk.partial', ['count' => $successCount, 'total' => $totalCount]))
            ->missingBulkAuthorizationFailureNotificationMessage(fn (int $count): string => (string) __('kami::admin.bulk.reason', ['reason' => __('kami::admin.bulk.forbidden'), 'count' => $count]))
            ->action(fn (BulkAction $action, EloquentCollection $records, array $data) => self::eachRow($action, $records, $data, $refuse, $write));
    }

    /**
     * @param  EloquentCollection<int, Entry>  $records
     * @param  array<mixed>  $data
     * @param  Closure(Entry, array<mixed>): ?string  $refuse
     * @param  Closure(Entry, array<mixed>): mixed  $write
     */
    private static function eachRow(BulkAction $action, EloquentCollection $records, array $data, Closure $refuse, Closure $write): void
    {
        foreach ($records as $record) {
            try {
                if (($reason = $refuse($record, $data)) === null) {
                    $write($record, $data);
                }
            } catch (ValidationException $exception) {
                $reason = $exception->validator->errors()->first();
            }

            if ($reason !== null) {
                // A reason may hold titles, and Filament renders the report as HTML.
                $action->reportBulkProcessingFailure($reason, fn (int $count): string => (string) __('kami::admin.bulk.reason', ['reason' => e($reason), 'count' => $count]));
            }
        }
    }

    public static function getPages(): array
    {
        // Filament also asks with no configuration, to register every page class; and while routes register, when a declaration may still be invalid (see `ContentTypes`).
        $key = Filament::getCurrentResourceConfigurationKey();
        $type = collect(resolve(ContentTypes::class)->declared())->first(fn (ContentType $type): bool => $type->handle() === $key);

        // One page, named `index` as Filament's navigation and links expect; `kami:install` creates its Entry.
        if ($type instanceof Singleton) {
            return ['index' => EditEntry::route('/{locale?}')];
        }

        return [
            'index' => ListEntries::route('/'),
            'create' => CreateEntry::route('/create'),
            // The default Locale's at `/edit`, another's at `/edit/en`, as on the front.
            'edit' => EditEntry::route('/{record}/edit/{locale?}'),
        ];
    }

    /** The edit page of an Entry, in another Locale than the default one when named; the default Locale and `'*'` lead to `/edit`. */
    public static function editUrl(Entry $entry, ?string $locale): string
    {
        $locale = in_array($locale, ['*', Site::current()->defaultLocale()], true) ? null : $locale;

        return self::type() instanceof Singleton ? self::getUrl('index', ['locale' => $locale]) : self::getUrl('edit', ['record' => $entry, 'locale' => $locale]);
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
     * A relation Field's input, searched on the server. A keyboard reorders by removing and picking again (ADR 0007).
     */
    public static function picker(RelationField $field): SelectInput
    {
        $picker = SelectInput::make($field->handle)
            ->multiple()
            ->reorderable()
            ->searchable()
            ->getSearchResultsUsing(fn (string $search, mixed $livewire): array => self::pickerTitles(self::matchingIds($field->linkable(), $search), $livewire))
            ->getOptionLabelsUsing(fn (array $values, mixed $livewire): array => self::pickerTitles($values, $livewire));

        return $field->isCreatable() ? self::withQuickCreate($picker, $field->targets()[0]) : $picker;
    }

    /** Searched on the server. Moving changes URLs once they follow the tree, so it takes `reorder` (ADR 0014); `save()` holds the rule, this shows it. On a create page, the Parent whose children the list showed. */
    private static function parentInput(): SelectInput
    {
        // Itself and its children stay offered: `SaveEntry` refuses them.
        return self::sameTypeInput('parent')
            ->label(__('kami::admin.fields.parent'))
            ->default(fn (mixed $livewire): ?string => $livewire instanceof CreateEntry ? $livewire->parent : null)
            ->disabled(fn (): bool => ! self::can('reorder'));
    }

    /** One Entry of the type in the Site, outside the Trash, searched on the server by title. */
    private static function sameTypeInput(string $name): SelectInput
    {
        return SelectInput::make($name)
            ->searchable()
            ->getSearchResultsUsing(fn (string $search, mixed $livewire): array => self::pickerTitles(self::matchingIds(self::getEloquentQuery()->where('site', Site::current()->handle), $search), $livewire))
            ->getOptionLabelUsing(fn (string $value, mixed $livewire): string => self::pickerTitles([$value], $livewire)[$value] ?? __('kami::admin.fields.untitled'));
    }

    /**
     * The candidates titled with the search, in any Locale, of the types the User may view.
     *
     * @param  Builder<Entry>  $candidates
     * @return array<mixed>
     */
    private static function matchingIds(Builder $candidates, string $search): array
    {
        return Translation::query()->whereIn('entry_id', $candidates->whereIn('type', self::viewableTypes())->select('id')->toBase())->whereLike('title', "%{$search}%")->distinct()->limit(50)->pluck('entry_id')->all();
    }

    /**
     * Creates a target from its title, through `SaveEntry`, in the Locale edited, under the target's `create` and `view` Permissions. A Term is published by default (ADR 0002), unless the editor may not publish its type (ADR 0019); anything else is a Draft.
     *
     * @param  class-string<ContentType>  $target
     */
    private static function withQuickCreate(SelectInput $picker, string $target): SelectInput
    {
        $type = resolve(ContentTypes::class)->find($target) ?? throw new LogicException("No Content Type [{$target}] is declared in kami.types.");
        $allows = fn (string $ability): bool => Gate::forUser(Filament::auth()->user())->allows($ability, [Entry::class, $type->handle()]);
        $label = __('kami::admin.actions.create_target', ['type' => $type->label()]);

        return $picker
            ->createOptionForm([TextInput::make('title')->label(__('kami::admin.fields.title'))->required()])
            // Offering a link takes `view`: a User never creates a target they would then see as restricted.
            ->createOptionAction(fn (Action $action): Action => $action->authorize(fn (): bool => $allows('create') && $allows('viewAny'))
                // Named, as a form may hold several pickers (WCAG 2.4.6).
                ->label($label)
                ->modalHeading($label))
            ->createOptionUsing(function (array $data, mixed $livewire) use ($target, $allows): string {
                try {
                    return resolve(SaveEntry::class)->handle($target, ['title' => $data['title'], ...$allows('publish') ? [] : ['status' => Translation::DRAFT]], locale: $livewire instanceof EditEntry ? $livewire->locale : null, actor: Filament::auth()->user())->id;
                } catch (ValidationException $exception) {
                    // The form holds the title alone, the slug and the Path derive from it, and the declarations refuse a target needing more.
                    // ponytail: Filament's internal state path of an action opened first; V1 lifts it if a picker's action ever opens from another one.
                    throw ValidationException::withMessages(['mountedActions.0.data.title' => Arr::flatten($exception->errors())]);
                }
            });
    }

    public static function plugin(): KamiPlugin
    {
        $plugin = Filament::getPlugin('kami');

        return $plugin instanceof KamiPlugin ? $plugin : throw new LogicException('EntryResource is served by a panel with KamiPlugin.');
    }

    /** The Parent the request's `?parent=` names, or null; 404 when the type or the Site has no such Parent. */
    public static function requestedParent(): ?string
    {
        $parent = request()->query('parent');

        if ($parent === null) {
            return null;
        }

        abort_unless(self::type()->isHierarchical() && is_string($parent) && self::getEloquentQuery()->where('site', Site::current()->handle)->whereKey($parent)->exists(), 404);

        return $parent;
    }

    /**
     * The way down to a level: the type's list, then each Parent, all linked to their children.
     *
     * @return array<string, string>
     */
    public static function levelBreadcrumbs(?string $parent, mixed $livewire): array
    {
        $breadcrumbs = [self::getUrl() => self::getPluralModelLabel()];

        if ($parent === null) {
            return $breadcrumbs;
        }

        $ids = array_reverse(Ancestors::ids($parent));
        $titles = self::pickerTitles($ids, $livewire);

        foreach ($ids as $id) {
            $breadcrumbs[self::getUrl(parameters: ['parent' => $id])] = $titles[$id] ?? __('kami::admin.fields.untitled');
        }

        return $breadcrumbs;
    }

    /**
     * On a hierarchical type, Cancel leads to the level, not to the browser's previous page: after a save or a Locale switch, that is not the list.
     *
     * @param  Closure(): ?string  $parent
     */
    public static function cancelToLevel(Action $cancel, Closure $parent): Action
    {
        return self::type()->isHierarchical()
            ? $cancel->alpineClickHandler(null)->url(fn (): string => self::getUrl(parameters: array_filter(['parent' => $parent()])))
            : $cancel;
    }

    /**
     * Each Entry's title in the Locale edited, or the default one's, or any: a target titled elsewhere stays recognisable.
     * An Entry of a type the User may not view is labelled as restricted, with no title, so a pick someone else made stays removable; an id no Entry has gets no label.
     *
     * @param  array<mixed>  $entries
     * @return array<string, string>
     */
    public static function pickerTitles(array $entries, mixed $livewire): array
    {
        $default = Site::current()->defaultLocale();
        $edited = $livewire instanceof EditEntry ? $livewire->locale : $default;
        /** @var array<string, string> $types */
        $types = Entry::query()->withUnpublished()->withTrashed()->whereKey($entries)->pluck('type', 'id')->all();
        $restricted = array_keys(array_diff($types, self::viewableTypes()));

        /** @var array<string, string> The preferred last, so it wins. */
        $titles = Translation::query()->whereIn('entry_id', array_diff(array_keys($types), $restricted))->whereNotNull('title')->get()
            ->sortBy(fn (Translation $translation): int => match ($translation->locale) {
                $edited => 2,
                $default, '*' => 1,
                default => 0,
            })
            ->pluck('title', 'entry_id')->all();

        /** @var array<string, string> */
        $labels = array_fill_keys($restricted, __('kami::admin.fields.restricted'));

        return $titles + $labels;
    }

    /**
     * The types whose Entries a picker offers and titles: offering a link takes `view` on the target (ADR 0014).
     *
     * @return list<string>
     */
    private static function viewableTypes(): array
    {
        $gate = Gate::forUser(Filament::auth()->user());

        return array_values(array_filter(
            array_map(fn (ContentType $type): string => $type->handle(), resolve(ContentTypes::class)->all()),
            fn (string $type): bool => $gate->allows("kami.entries.{$type}.view"),
        ));
    }

    /**
     * Saves through `SaveEntry`, the one way content is written (ADR 0011), with its errors on the form's fields.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $state  The form's raw state, whose rows the errors must name.
     * @param  DateTimeInterface|null  $updatedAt  The Entry's `updated_at` as the page loaded it: `SaveEntry` refuses a save over a change made since.
     */
    public static function save(array $data, ?Entry $entry = null, ?string $status = null, ?string $locale = null, array $state = [], ?DateTimeInterface $updatedAt = null): Entry
    {
        $isNew = $entry?->translations->whereIn('locale', [$locale, '*'])->isEmpty() ?? true;

        // Saving a form's new Translation says Draft: only code naming no status gets a Taxonomy's published default, and the form's button takes no `publish`.
        if (self::type() instanceof Taxonomy && $isNew) {
            $status ??= Translation::DRAFT;
        }

        $data = array_diff_key($data, array_flip(self::keptFromUser($entry)));

        $saved = self::onForm(fn (): Entry => resolve(SaveEntry::class)->handle(self::type()->handle(), [...$data, ...array_filter(['status' => $status])], $entry, $locale, Filament::auth()->user(), $updatedAt), $state);

        self::remind($data, $saved->id);
        self::remindAlt([$saved->id], $locale);

        return $saved;
    }

    /**
     * The unsaved form as `SaveEntry` would write it, checked with the Draft rules and kept in the cache for this User only; returns the Preview's URL.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $state  The form's raw state, whose rows the errors must name.
     * @param  string  $token  The page's: each Preview of it replaces the last.
     */
    public static function preview(array $data, ?Entry $entry, string $locale, array $state, string $token): string
    {
        $data = array_diff_key($data, array_flip(self::keptFromUser($entry)));
        $draft = self::onForm(fn (): array => resolve(SaveEntry::class)->draft(self::type()->handle(), $data, $entry, $locale), $state);

        RenderPreview::store($token, EntrySnapshot::capture(self::type(), $entry, $draft, $locale));

        return (Filament::getCurrentPanel() ?? throw new LogicException('A Preview is made from a panel.'))->route('kami.preview', ['token' => $token]);
    }

    /**
     * Opens the Preview's tab on the click, before the round trip, which a popup blocker would refuse; a second click finds it by name and refreshes it.
     * An invalid form keeps its errors and closes a tab still blank. A Global Set or a type with no URL has no page to show.
     */
    public static function previewAction(): Action
    {
        return Action::make('preview')
            ->label(__('kami::admin.actions.preview'))
            ->color('gray')
            ->visible(fn (): bool => self::type()->isRoutable())
            ->alpineClickHandler(<<<'JS'
                const tab = window.open('', 'kami-preview');
                $wire.preview().then((url) => url ? tab.location.assign(url) : tab.location.href === 'about:blank' && tab.close());
                JS);
    }

    /**
     * Runs a `SaveEntry` call with its errors on the form's fields.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @param  array<string, mixed>  $state
     * @return T
     */
    private static function onForm(Closure $call, array $state): mixed
    {
        try {
            return $call();
        } catch (ValidationException $exception) {
            $errors = [];

            foreach ($exception->errors() as $key => $messages) {
                $errors['data.' . self::statePath($key, $state)] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Reminders on what was saved, never on what the User's Permissions kept out, kept until dismissed so the editor fixes them at their own pace (WCAG 2.2.1); they never stop a save (ADR 0016).
     *
     * One per Entry and Field: a save again replaces it rather than stacking another.
     *
     * @param  array<string, mixed>  $data
     */
    private static function remind(array $data, string $entryId): void
    {
        foreach (self::type()->flatFields() as $field) {
            $value = $field instanceof RichText && array_key_exists($field->handle, $data) ? $field->normalise($data[$field->handle]) : null;
            $reminders = is_array($value) ? RichTextInput::reminders($field, new Document($value)) : [];

            if ($reminders !== []) {
                Notification::make("kami-reminders-{$entryId}-{$field->handle}")->warning()->persistent()
                    ->title(__('kami::admin.reminders.title', ['field' => self::type()->fieldLabel($field->handle)]))
                    ->body('<ul><li>' . implode('</li><li>', array_map(e(...), $reminders)) . '</li></ul>')
                    ->send();
            }
        }
    }

    /**
     * The images that the Entries' published or Scheduled Translations in that Locale show without alt text, by name, on one notification
     * kept until dismissed: a warning, never a block (ADR 0017). The Fields are read as the front reads them, a blank translatable one falling
     * back; an image counts as missing its alt text as `Media::missingAlt()` says, from the default Locale's, whatever the Translation's Locale.
     *
     * @param  array<string>  $entries
     */
    private static function remindAlt(array $entries, ?string $locale): void
    {
        $handles = array_column(array_filter(self::type()->relationFields(), fn (Field $field): bool => $field instanceof MediaField), 'handle');

        if ($handles === [] || $entries === []) {
            return;
        }

        $locale ??= Site::current()->defaultLocale();
        $shown = Entry::query()->withUnpublished()->inLocale($locale)->whereKey($entries)->with($handles)->get()
            ->filter(fn (Entry $entry): bool => $entry->translations->whereIn('locale', [$locale, '*'])->first()?->status === Translation::PUBLISHED)
            ->flatMap(fn (Entry $entry): Collection => collect($handles)->flatMap(fn (string $handle): Collection => Collection::wrap($entry->getAttribute($handle))))
            ->pluck('id')->all();
        /** @var list<string> $names */
        $names = $shown === [] ? [] : Media::query()->missingAlt()->whereKey($shown)->orderBy('name')->pluck('name')->all();

        if ($names !== []) {
            // One per set of Entries and Locale: saving the same Entry again replaces it.
            sort($entries);
            Notification::make('kami-reminders-alt-' . hash('xxh128', implode(',', $entries) . "|{$locale}"))->warning()->persistent()
                ->title(__('kami::admin.reminders.alt_title'))
                ->body('<p>' . e(__('kami::admin.reminders.alt_body')) . '</p><ul><li>' . implode('</li><li>', array_map(e(...), $names)) . '</li></ul>')
                ->send();
        }
    }

    /** The panel's name for the User of a key, `null` for none or a User deleted since: the Last modifier `SaveEntry`'s refusal names, through `PluginRegistration`. */
    public static function userName(int | string | null $key): ?string
    {
        $user = $key !== null ? self::userProvider()?->retrieveById($key) : null;

        return $user instanceof Authenticatable ? Filament::getUserName($user) : null;
    }

    /**
     * The names of these panel Users, by key, in one query; a key with no User is left out, as is every key on a panel whose Users are not Eloquent models.
     *
     * @param  list<int|string>  $keys
     * @return array<array-key, string>
     */
    public static function userNames(array $keys): array
    {
        $user = self::newUser();

        // ponytail: a panel whose Users are not Eloquent names nobody, so the Audit log reads every actor as "Deleted User (id)"; none exists in the
        // Workbench or the starter kit, V1 reads its provider one key at a time if a friction shows one.
        if ($user === null || $keys === []) {
            return [];
        }

        $names = [];

        foreach ($user->newQuery()->whereIn($user->getAuthIdentifierName(), $keys)->get() as $found) {
            $key = $found->getAuthIdentifier();

            if (is_int($key) || is_string($key)) {
                $names[$key] = Filament::getUserName($found);
            }
        }

        return $names;
    }

    /**
     * `creator` and `lastModifier` on `Entry`, the panel's Users the table loads for its visible columns only, in one query each.
     *
     * The admin's own: not public surface, absent without a panel, a `LogicException` on a panel whose Users are not Eloquent models.
     */
    public static function relateUsers(): void
    {
        foreach (['creator' => 'created_by', 'lastModifier' => 'updated_by'] as $relation => $key) {
            Entry::resolveRelationUsing($relation, function (Entry $entry) use ($relation, $key): BelongsTo {
                $user = self::newUser() ?? throw new LogicException('The panel\'s Users are not Eloquent models.');

                return $entry->belongsTo($user::class, $key, $user->getAuthIdentifierName(), $relation);
            });
        }
    }

    /** A new User of the panel's Eloquent provider; `null` for another guard or provider, whose Users no query loads in a batch. */
    private static function newUser(): (Model & Authenticatable) | null
    {
        $provider = self::userProvider();
        $user = $provider instanceof EloquentUserProvider ? $provider->createModel() : null;

        return $user instanceof Authenticatable ? $user : null;
    }

    private static function userProvider(): ?UserProvider
    {
        $guard = Filament::auth();

        // ponytail: a panel guard other than a session one names nobody, as none exists in the Workbench or the starter kit; V1 reads its provider if a friction shows one.
        return $guard instanceof SessionGuard ? $guard->getProvider() : null;
    }

    /**
     * What the User's Permissions leave out of a save, as a disabled input would: a site's form can lose that `disabled`, so the rule holds here (ADR 0019).
     *
     * @return list<string>
     */
    private static function keptFromUser(?Entry $entry): array
    {
        return [
            ...(self::can('publish', $entry) ? [] : ['published_at']),
            ...(self::can('reorder') ? [] : ['parent']),
            ...(self::sharedFieldsLocked($entry) ? array_map(fn (Field $field): string => $field->handle, array_filter(self::type()->flatFields(), self::isShared(...))) : []),
        ];
    }

    /** On an untranslatable type every Field has its one value, with no other Locale to share it with. */
    private static function isShared(Field $field): bool
    {
        return self::type()->isTranslatable() && ! self::type()->isTranslatable($field);
    }

    /** One value for every Locale: changing it changes Published and Scheduled Translations too, which takes `publish` (ADR 0019). */
    private static function sharedFieldsLocked(?Entry $entry): bool
    {
        return $entry instanceof Entry && ! self::can('publish', $entry)
            && $entry->translations->contains(fn (Translation $translation): bool => $translation->status === Translation::PUBLISHED);
    }

    /** `SaveEntry` numbers a Repeater's rows, the form keys them by UUID: `links.0.url` is `links.<uuid>.url`. */
    private static function statePath(string $key, mixed $state): string
    {
        $path = [];

        foreach (explode('.', $key) as $segment) {
            if (is_array($state) && ctype_digit($segment)) {
                $segment = array_keys($state)[(int) $segment] ?? $segment;
            }

            $path[] = $segment;
            $state = is_array($state) ? $state[$segment] ?? null : null;
        }

        return implode('.', $path);
    }

    /** One Locale's state, or the `'*'` Translation's as `state` for an untranslatable type. */
    private static function stateColumn(string $locale): TextColumn
    {
        return TextColumn::make($locale === '*' ? 'state' : "state_{$locale}")
            ->label($locale === '*' ? __('kami::admin.state.label') : __('kami::admin.state.label_in', ['locale' => $locale]))
            ->badge()
            ->state(fn (Entry $record): string => __('kami::admin.state.' . self::state($record->translations->firstWhere('locale', $locale))))
            ->description(fn (Entry $record, TextColumn $column): ?string => self::scheduledAt($record->translations->firstWhere('locale', $locale), $column->getTable()->getDefaultDateTimeDisplayFormat()));
    }

    /** The Creator or Last modifier as the panel names them, nobody for none or a User deleted since. */
    private static function userColumn(string $relation, string $label): TextColumn
    {
        // Named after the relation, so Filament eager-loads it while the column shows.
        return TextColumn::make("{$relation}.name")
            ->label($label)
            ->toggleable(isToggledHiddenByDefault: true)
            // ponytail: hidden on a panel whose Users are not Eloquent models, which no query loads in a batch; V1 if a friction shows one.
            ->visible(fn (): bool => self::newUser() !== null)
            // The loaded relation, never the attribute: a Field may take the relation's name.
            ->state(fn (Entry $record): ?string => ($user = $record->getRelationValue($relation)) instanceof Authenticatable ? Filament::getUserName($user) : null)
            ->default(__('kami::admin.users.nobody'));
    }

    /** A Scheduled Translation's date in the Site's time zone; null in any other state. */
    public static function scheduledAt(?Translation $translation, string $format): ?string
    {
        return self::state($translation) === 'scheduled' ? $translation?->published_at?->setTimezone(Site::current()->timezone)->translatedFormat($format) : null;
    }

    /** Publish, or Schedule while the form's date, in the Site's time zone, is to come: what the click will do. */
    public static function publishLabel(mixed $date): string
    {
        try {
            $future = is_string($date) && $date !== '' && CarbonImmutable::parse($date, Site::current()->timezone)->isFuture();
        } catch (InvalidFormatException) {
            // The form state is the client's: an unreadable date is the save's error to show.
            $future = false;
        }

        return (string) __($future ? 'kami::admin.actions.schedule' : 'kami::admin.actions.publish');
    }

    /** Draft, Scheduled or Published, as visitors see the Translation now (ADR 0019), or missing. */
    public static function state(?Translation $translation): string
    {
        return match (true) {
            ! $translation instanceof Translation => 'missing',
            $translation->status !== Translation::PUBLISHED => 'draft',
            Visibility::isVisible($translation) => 'published',
            default => 'scheduled',
        };
    }

    /**
     * The declaration as the form arranges it: adjacent Tabs form one set.
     *
     * @param  list<Field|Layout>  $declared
     * @param  array<string, string>  $lang
     * @param  Collection<string, Field>  $fields
     * @return list<Component>
     */
    private static function components(array $declared, array $lang, Collection $fields): array
    {
        $components = [];
        $tabs = [];

        foreach ($declared as $item) {
            if ($item instanceof Tab) {
                $tabs[] = FilamentTab::make(self::type()->tabLabel($item->handle))->schema(self::components($item->children, $lang, $fields));

                continue;
            }

            if ($tabs !== []) {
                $components[] = Tabs::make()->tabs($tabs);
                $tabs = [];
            }

            $components[] = $item instanceof Layout
                ? FilamentSection::make(self::type()->sectionLabel($item->handle))->schema(self::components($item->children, $lang, $fields))
                : self::component($item, $lang, $fields);
        }

        if ($tabs !== []) {
            $components[] = Tabs::make()->tabs($tabs);
        }

        return $components;
    }

    /**
     * @param  array<string, string>  $lang
     * @param  Collection<string, Field>  $fields
     */
    private static function component(Field $field, array $lang, Collection $fields): Component
    {
        $translatable = ! self::isShared($field);
        $target = $fields->get($field->conditionTarget() ?? '');
        $component = self::input($field, $field->handle, $translatable ? $lang : [], $field->defaultValue());

        // The condition `SaveEntry` reads, on the form's state; a hidden component is not sent, and `SaveEntry` clears its value when the save gives the target.
        if ($target instanceof Field) {
            $component->visible(fn (Get $get): bool => $field->isVisibleGiven($target, $get($target->handle)));
        }

        // A target refreshes the form as it changes, typed text once it loses focus.
        if ($fields->contains(fn (Field $other): bool => $other->conditionTarget() === $field->handle)) {
            $component->live(onBlur: $component instanceof TextInput || $component instanceof Textarea);
        }

        if ($translatable) {
            return $component;
        }

        $shared = __('kami::admin.fields.shared');

        if ($component instanceof FilamentSection) {
            $component->description(trim($shared . ' ' . self::type()->fieldHelp($field->handle)));
        } elseif ($component instanceof FormField) {
            $component->hint($shared);
        }

        return $component->disabled(fn (?Entry $record): bool => self::sharedFieldsLocked($record));
    }

    /**
     * A Field's input, its sub-Fields' inside it; a Group is a heading over its values, under its own state path.
     *
     * @param  string  $key  Where its labels live under `fields.`.
     * @param  array<string, string>  $lang
     * @param  mixed  $default  A Group's goes to its sub-Fields: a heading holds no state of its own.
     */
    private static function input(Field $field, string $key, array $lang, mixed $default = null): Component
    {
        $label = self::type()->fieldLabel($key);
        $help = self::type()->fieldHelp($key);

        if ($field instanceof StructuredField) {
            $children = [];

            foreach ($field->fields() as $handle => $sub) {
                $children[] = self::input($sub, "{$key}.fields.{$handle}", $lang, $field instanceof Group && is_array($default) ? $default[$handle] ?? null : null);
            }

            return $field instanceof Group
                ? FilamentSection::make($label)->description($help)->statePath($field->handle)->schema($children)
                // Buttons as well as dragging, for a keyboard (WCAG 2.1.1).
                : RepeaterInput::make($field->handle)->label($label)->helperText($help)->schema($children)->default($default)->defaultItems(0)->reorderableWithButtons();
        }

        $component = self::plugin()->component($field);
        $component->label($label)->helperText($help);

        $component->default($field instanceof RichText && is_array($default) ? RichTextInput::toEditor($default) : $default);

        // A registered Select names its labels by handle alone.
        if ($field instanceof Select && $component instanceof SelectInput && $key !== $field->handle) {
            $component->options(array_combine($field->optionKeys(), array_map(fn (string $option): string => self::type()->optionLabel($key, $option), $field->optionKeys())));
        }

        // A toggle or a site's component may type no text, so may have no input to mark.
        if (method_exists($component, 'extraInputAttributes')) {
            $component->extraInputAttributes($lang);
        }

        return $component;
    }
}
