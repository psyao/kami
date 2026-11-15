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
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource as FilamentResource;
use Filament\Resources\ResourceConfiguration;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;
use Psyao\Kami\Admin\Internal\MenuItemResource\ManageMenuItems;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Menus\Actions\DeleteMenuItem;
use Psyao\Kami\Menus\Actions\MoveMenuItem;
use Psyao\Kami\Menus\Actions\SaveMenuItem;
use Psyao\Kami\Menus\Internal\Menus;
use Psyao\Kami\Menus\MenuItem;
use Psyao\Kami\Sites\Internal\Site;
use UnitEnum;

/**
 * One declared Menu's items, registered once per Menu under its handle: the whole tree in one native table, written through the core actions only (ADR 0025). No drag-and-drop and no tree plugin (ADR 0023).
 *
 * @internal
 *
 * @extends FilamentResource<MenuItem>
 */
final class MenuItemResource extends FilamentResource
{
    #[\Override]
    protected static ?string $model = MenuItem::class;

    #[\Override]
    protected static bool $hasTitleCaseModelLabel = false;

    #[\Override]
    protected static ?string $configurationClass = ResourceConfiguration::class;

    /** The handle of the Menu being served: the configuration's key. */
    public static function menu(): string
    {
        return self::getConfiguration()?->getKey() ?? throw new LogicException('MenuItemResource is served through a configuration per Menu.');
    }

    public static function configure(string $menu): ResourceConfiguration
    {
        return self::make($menu)->slug("menus/{$menu}");
    }

    public static function getModelLabel(): string
    {
        return __('kami::admin.menus.label');
    }

    /** The Menu's own name heads its page, its breadcrumb and its navigation item. */
    public static function getPluralModelLabel(): string
    {
        return Menus::label(self::menu());
    }

    public static function getNavigationLabel(): string
    {
        return self::getPluralModelLabel();
    }

    /** One Permission for every Menu and every write (ADR 0025). */
    public static function getAuthorizationResponse(string | UnitEnum $action, ?Model $record = null): Response
    {
        return Gate::forUser(Filament::auth()->user())->inspect('kami.menus.manage');
    }

    public static function getEloquentQuery(): Builder
    {
        return MenuItem::query()->where(['site' => Site::current()->handle, 'menu' => self::menu()]);
    }

    public static function form(Schema $schema): Schema
    {
        $locales = Site::current()->locales;
        $kind = fn (string $kind): Closure => fn (Get $get): bool => $get('kind') === $kind;

        return $schema->columns(1)->components([
            ToggleButtons::make('kind')->label(__('kami::admin.menus.fields.kind'))
                ->options(['entry' => __('kami::admin.menus.kinds.entry'), 'archive' => __('kami::admin.menus.kinds.archive'), 'url' => __('kami::admin.menus.kinds.url')])
                ->default('entry')->inline()->live()->required(),
            Select::make('entry')->label(__('kami::admin.menus.kinds.entry'))
                ->searchable()
                ->getSearchResultsUsing(fn (string $search, mixed $livewire): array => EntryResource::linkableTitles($search, $livewire))
                ->getOptionLabelUsing(fn (mixed $value, mixed $livewire): ?string => is_string($value) ? EntryResource::pickerTitles([$value], $livewire)[$value] ?? null : null)
                ->visible($kind('entry')),
            Select::make('archive')->label(__('kami::admin.menus.kinds.archive'))
                ->options(fn (): array => self::archives())
                ->visible($kind('archive')),
            Grid::make(min(count($locales), 2))->schema(array_map(
                fn (string $locale): TextInput => TextInput::make("url.{$locale}")->label(__('kami::admin.menus.fields.url', ['LOCALE' => strtoupper($locale)]))->maxLength(2048),
                $locales,
            ))->visible($kind('url')),
            Grid::make(min(count($locales), 2))->schema(array_map(
                fn (string $locale): TextInput => TextInput::make("label.{$locale}")->label(__('kami::admin.menus.fields.label', ['LOCALE' => strtoupper($locale)]))->maxLength(255),
                $locales,
            )),
            Select::make('parent')->label(__('kami::admin.menus.fields.parent'))
                ->placeholder(__('kami::admin.menus.fields.parent_none'))
                ->options(fn (?MenuItem $record, ManageMenuItems $livewire): array => $livewire->parentOptions($record))
                ->hidden(fn (): bool => Menus::depth(self::menu()) === 1),
            Toggle::make('new_tab')->label(__('kami::admin.menus.fields.new_tab')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query, ManageMenuItems $livewire): Builder => $livewire->inTreeOrder($query))
            ->paginated(false)
            ->columns([
                TextColumn::make('label')->label(__('kami::admin.menus.columns.label'))
                    ->state(fn (MenuItem $record, ManageMenuItems $livewire): string => $livewire->label($record))
                    // Its level said to screen readers too, not only shown by the indent.
                    ->prefix(fn (MenuItem $record, ManageMenuItems $livewire): ?string => $livewire->level($record) > 1 ? __('kami::admin.menus.level', ['level' => $livewire->level($record)]) . ': ' : null)
                    ->extraAttributes(fn (MenuItem $record, ManageMenuItems $livewire): array => ['class' => "kami-menu-level-{$livewire->level($record)}"]),
                TextColumn::make('target')->label(__('kami::admin.menus.columns.target'))
                    ->state(fn (MenuItem $record, ManageMenuItems $livewire): string => $livewire->target($record)),
                IconColumn::make('new_tab')->label(__('kami::admin.menus.fields.new_tab'))->boolean()->visibleFrom('md'),
            ])
            ->recordActions([
                self::moveAction('up', Heroicon::ArrowUp, -1),
                self::moveAction('down', Heroicon::ArrowDown, 1),
                EditAction::make()
                    ->fillForm(fn (MenuItem $record): array => [
                        'kind' => $record->entry_id !== null ? 'entry' : ($record->archive !== null ? 'archive' : 'url'),
                        'entry' => $record->entry_id,
                        'archive' => $record->archive,
                        'url' => $record->url ?? [],
                        'label' => $record->label ?? [],
                        'parent' => $record->parent_id,
                        'new_tab' => $record->new_tab,
                    ])
                    ->using(fn (MenuItem $record, array $data, Action $action): MenuItem => self::save($data, $action, $record)),
                DeleteAction::make()
                    ->modalDescription(__('kami::admin.menus.delete'))
                    ->using(function (MenuItem $record): bool {
                        resolve(DeleteMenuItem::class)->handle($record, Filament::auth()->user());

                        return true;
                    }),
            ]);
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()->using(fn (array $data, Action $action): MenuItem => self::save($data, $action));
    }

    public static function getPages(): array
    {
        return ['index' => ManageMenuItems::route('/')];
    }

    /**
     * One reachable by Tab and Enter, disabled at its end of the siblings: no drag-and-drop (ADR 0025).
     */
    private static function moveAction(string $name, Heroicon $icon, int $step): Action
    {
        // Named with its row's label, as Filament names its own move buttons: a screen reader lists them apart.
        return Action::make($name)->label(fn (MenuItem $record, ManageMenuItems $livewire): string => __("kami::admin.menus.{$name}", ['label' => $livewire->label($record)]))
            ->icon($icon)->iconButton()->color('gray')
            // `kami.js` gives it focus back once the moved row re-renders (WCAG 2.4.3).
            ->extraAttributes(['data-kami-move' => $name])
            ->authorize(fn (): bool => self::can('update'))
            ->disabled(fn (MenuItem $record, ManageMenuItems $livewire): bool => ! $livewire->canMove($record, $step))
            ->action(fn (MenuItem $record): MenuItem => resolve(MoveMenuItem::class)->handle($record, $record->position + $step, Filament::auth()->user()));
    }

    /**
     * Through `SaveMenuItem`, the chosen target only, each Locale's label and address at once; its refusals land on the modal's inputs.
     *
     * @param  array<mixed>  $data  The modal's state, as Filament hands it.
     */
    private static function save(array $data, Action $action, ?MenuItem $item = null): MenuItem
    {
        $locales = Site::current()->locales;
        $kind = $data['kind'] ?? null;
        $none = array_fill_keys($locales, null);
        $values = [
            'entry' => $kind === 'entry' ? $data['entry'] ?? null : null,
            'archive' => $kind === 'archive' ? $data['archive'] ?? null : null,
            // Each Locale given, so a Locale emptied, or another target chosen, clears the stored address.
            'url' => $kind === 'url' && is_array($data['url'] ?? null) ? [...$none, ...$data['url']] : $none,
            'label' => is_array($data['label'] ?? null) ? [...$none, ...$data['label']] : $none,
            'new_tab' => (bool) ($data['new_tab'] ?? false),
        ];

        // Left out where the Menu has a single level: the stored Parent stays.
        if (array_key_exists('parent', $data)) {
            $values['parent'] = $data['parent'];
        }

        try {
            return resolve(SaveMenuItem::class)->handle(self::menu(), $values, $item, Filament::auth()->user());
        } catch (ValidationException $exception) {
            $errors = [];
            $chosen = match ($kind) {
                'archive' => 'archive',
                'url' => 'url.' . Site::current()->defaultLocale(),
                default => 'entry',
            };

            foreach ($exception->errors() as $key => $messages) {
                $errors["mountedActions.{$action->getNestingIndex()}.data." . ($key === 'target' ? $chosen : $key)] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }

    /** @return array<string, string> The types with an Archive, by handle. */
    private static function archives(): array
    {
        $types = array_filter(resolve(ContentTypes::class)->all(), fn (ContentType $type): bool => $type->hasArchive());

        return array_combine(array_map(fn (ContentType $type): string => $type->handle(), $types), array_map(fn (ContentType $type): string => $type->pluralLabel(), $types));
    }
}
