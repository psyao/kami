<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field as FormField;
use Filament\Forms\Components\Repeater as RepeaterInput;
use Filament\Forms\Components\Select as SelectInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource as FilamentResource;
use Filament\Resources\ResourceConfiguration;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section as FilamentSection;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab as FilamentTab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Psyao\Kami\Admin\Internal\EntryResource\CreateEntry;
use Psyao\Kami\Admin\Internal\EntryResource\EditEntry;
use Psyao\Kami\Admin\Internal\EntryResource\ListEntries;
use Psyao\Kami\Admin\KamiPlugin;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\Fields\Group;
use Psyao\Kami\Content\Fields\RelationField;
use Psyao\Kami\Content\Fields\Select;
use Psyao\Kami\Content\Fields\StructuredField;
use Psyao\Kami\Content\GlobalSet;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Content\Internal\EntryBuilder;
use Psyao\Kami\Content\Internal\Translation;
use Psyao\Kami\Content\Internal\Visibility;
use Psyao\Kami\Content\Layout;
use Psyao\Kami\Content\Singleton;
use Psyao\Kami\Content\Tab;
use Psyao\Kami\Content\Taxonomy;
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
        $timezone = Site::current()->timezone;
        $livewire = $schema->getLivewire();
        // The Locale of the values typed, so assistive technology reads them in their language (WCAG 3.1.2).
        $locale = $livewire instanceof EditEntry ? $livewire->locale : Site::current()->defaultLocale();
        $lang = self::locales() === [] ? [] : ['lang' => $locale];
        // Read from the page, not the schema's record: saving replaces it, and the first save stores a slug. No slug stored, no Path can move.
        $hasStoredSlug = fn (): bool => $livewire instanceof EditEntry && filled($livewire->getRecord()->slug);
        $hasSlug = self::type()->hasSlug();
        // Its values simply apply: `SaveEntry` publishes it and titles it with its label.
        $hasPublication = ! self::type() instanceof GlobalSet;

        return $schema->columns(1)->components([
            TextInput::make('title')->label(__('kami::admin.fields.title'))->extraInputAttributes($lang)->visible($hasPublication)
                ->live(onBlur: true, condition: fn (): bool => $hasSlug && ! $hasStoredSlug())
                // The hook also runs on a save's deferred update, hence the check. Blank or the previous title's slug: not typed by hand.
                ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state) use ($hasSlug, $hasStoredSlug, $locale): void {
                    if ($hasSlug && ! $hasStoredSlug() && (blank($get('slug')) || $get('slug') === Path::slugFrom($old ?? '', $locale))) {
                        $set('slug', Path::slugFrom($state ?? '', $locale));
                    }
                }),
            TextInput::make('slug')->label(__('kami::admin.fields.slug'))->extraInputAttributes($lang)->visible($hasSlug)
                // ponytail: a warning, as the old address answers 404; M7's redirect on slug change removes it.
                ->helperText(fn (): string => __($hasStoredSlug() ? 'kami::admin.fields.slug_moves' : 'kami::admin.fields.slug_help')),
            ...(self::type()->isHierarchical() ? [self::parentInput()] : []),
            ...self::components(self::type()->fields(), $lang, collect(self::type()->flatFields())->keyBy('handle')),
            // The date decides when a published Translation goes live, so it takes `publish`; a disabled field is not saved (ADR 0019).
            DateTimePicker::make('published_at')->visible($hasPublication)->label(__('kami::admin.fields.published_at'))->timezone($timezone)->helperText(__('kami::admin.fields.published_at_help', ['timezone' => $timezone]))
                ->disabled(fn (?Entry $record): bool => ! self::can('publish', $record)),
        ]);
    }

    public static function table(Table $table): Table
    {
        if (self::type()->isOrderable()) {
            // Paginated: a whole type's rows could exceed shared hosting's memory limit; a row moves within its page (ADR 0023).
            $table->reorderable('position')->paginatedWhileReordering()->authorizeReorder(fn (): bool => self::can('reorder'))
                ->defaultSort(fn (EntryBuilder $query): Builder => $query->ordered());
        }

        $hierarchical = self::type()->isHierarchical();

        if ($hierarchical) {
            $table->modifyQueryUsing(fn (EntryBuilder $query, ListEntries $livewire): EntryBuilder => $query->where('parent_id', $livewire->parent));
        }

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
            ->recordActions([
                ...($hierarchical ? [Action::make('children')->label(__('kami::admin.actions.children'))->url(fn (Entry $record): string => self::getUrl(parameters: ['parent' => $record->id]))] : []),
                EditAction::make(),
            ]);
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

    /** The edit page of an Entry, in another Locale than the default one when named. */
    public static function editUrl(Entry $entry, ?string $locale): string
    {
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

    /** Searched on the server. Moving changes URLs once they follow the tree, so it takes `reorder`; a disabled field is not saved (ADR 0014). On a create page, the Parent whose children the list showed. */
    private static function parentInput(): SelectInput
    {
        return SelectInput::make('parent')
            ->label(__('kami::admin.fields.parent'))
            ->searchable()
            ->default(fn (): ?string => is_string($parent = request()->query('parent')) ? $parent : null)
            ->disabled(fn (): bool => ! self::can('reorder'))
            ->getSearchResultsUsing(function (string $search, mixed $livewire): array {
                $candidates = self::getEloquentQuery()->where('site', Site::current()->handle);

                return self::pickerTitles(self::matchingIds($livewire instanceof EditEntry ? $candidates->whereKeyNot($livewire->getRecord()->id) : $candidates, $search), $livewire);
            })
            ->getOptionLabelUsing(fn (string $value, mixed $livewire): string => self::pickerTitles([$value], $livewire)[$value] ?? __('kami::admin.fields.untitled'));
    }

    /**
     * The candidates titled with the search, in any Locale.
     *
     * @param  Builder<Entry>  $candidates
     * @return array<mixed>
     */
    private static function matchingIds(Builder $candidates, string $search): array
    {
        return Translation::query()->whereIn('entry_id', $candidates->select('id')->toBase())->whereLike('title', "%{$search}%")->distinct()->limit(50)->pluck('entry_id')->all();
    }

    /**
     * Creates a target from its title, through `SaveEntry`, in the Locale edited, under the target's `create` Permission. A Term is published by default (ADR 0002), unless the editor may not publish its type (ADR 0019); anything else is a Draft.
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
            ->createOptionAction(fn (Action $action): Action => $action->authorize(fn (): bool => $allows('create'))
                // Named, as a form may hold several pickers (WCAG 2.4.6).
                ->label($label)
                ->modalHeading($label))
            ->createOptionUsing(function (array $data, mixed $livewire) use ($target, $allows): string {
                try {
                    return resolve(SaveEntry::class)->handle($target, ['title' => $data['title'], ...$allows('publish') ? [] : ['status' => Translation::DRAFT]], locale: $livewire instanceof EditEntry ? $livewire->locale : null, actor: Filament::auth()->user())->id;
                } catch (ValidationException $exception) {
                    // The form holds the title alone, the slug and the Path derive from it, and the declarations refuse a target needing more.
                    // ponytail: Filament's internal state path of an action opened first; lifted if a picker's action ever opens from another one.
                    throw ValidationException::withMessages(['mountedActions.0.data.title' => Arr::flatten($exception->errors())]);
                }
            });
    }

    /**
     * Each Entry's title in the Locale edited, or the default one's, or any: a target titled elsewhere stays recognisable.
     *
     * @param  array<mixed>  $entries
     * @return array<string, string>
     */
    public static function pickerTitles(array $entries, mixed $livewire): array
    {
        $default = Site::current()->defaultLocale();
        $edited = $livewire instanceof EditEntry ? $livewire->locale : $default;

        /** @var array<string, string> The preferred last, so it wins. */
        return Translation::query()->whereIn('entry_id', $entries)->whereNotNull('title')->get()
            ->sortBy(fn (Translation $translation): int => match ($translation->locale) {
                $edited => 2,
                $default, '*' => 1,
                default => 0,
            })
            ->pluck('title', 'entry_id')->all();
    }

    /**
     * Saves through `SaveEntry`, the one way content is written (ADR 0011), with its errors on the form's fields.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $state  The form's raw state, whose rows the errors must name.
     */
    public static function save(array $data, ?Entry $entry = null, ?string $status = null, ?string $locale = null, array $state = []): Entry
    {
        $isNew = $entry?->translations->whereIn('locale', [$locale, '*'])->isEmpty() ?? true;

        // Saving a form's new Translation says Draft: only code naming no status gets a Taxonomy's published default, and the form's button takes no `publish`.
        if (self::type() instanceof Taxonomy && $isNew) {
            $status ??= Translation::DRAFT;
        }

        try {
            return resolve(SaveEntry::class)->handle(self::type()->handle(), [...$data, ...array_filter(['status' => $status])], $entry, $locale, Filament::auth()->user());
        } catch (ValidationException $exception) {
            $errors = [];

            foreach ($exception->errors() as $key => $messages) {
                $errors['data.' . self::statePath($key, $state)] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
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
        $translatable = ! self::type()->isTranslatable() || self::type()->isTranslatable($field);
        $target = $fields->get($field->conditionTarget() ?? '');
        $component = self::input($field, $field->handle, $translatable ? $lang : [], $field->defaultValue());

        // The condition `SaveEntry` reads, on the form's state; a hidden component is not sent, and `SaveEntry` clears its value.
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

        // One value for every Locale: changing it changes Published and Scheduled Translations too, which takes `publish` (ADR 0019).
        return $component
            ->disabled(fn (?Entry $record): bool => $record instanceof Entry && ! self::can('publish', $record)
                && $record->translations->contains(fn (Translation $translation): bool => $translation->status === Translation::PUBLISHED));
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

        $plugin = Filament::getPlugin('kami');
        $component = $plugin instanceof KamiPlugin ? $plugin->component($field) : throw new LogicException('EntryResource is served by a panel with KamiPlugin.');
        $component->label($label)->helperText($help);

        $component->default($default);

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
