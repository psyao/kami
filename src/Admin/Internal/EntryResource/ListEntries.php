<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal\EntryResource;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Components\Attributes\Exposed;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Psyao\Kami\Admin\Internal\EntryResource;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Internal\Ancestors;
use Psyao\Kami\Content\Internal\EntryBuilder;
use Psyao\Kami\Content\Internal\ReorderEntries;
use Psyao\Kami\Sites\Internal\Site;

/** @internal */
final class ListEntries extends ListRecords
{
    #[\Override]
    protected static string $resource = EntryResource::class;

    /** The Parent whose children the table shows; the root's when null. Links lead here, so nothing updates it in place. */
    #[Locked]
    public ?string $parent = null;

    public function mount(): void
    {
        parent::mount();

        $parent = request()->query('parent');

        if ($parent === null) {
            return;
        }

        abort_unless(EntryResource::type()->isHierarchical() && is_string($parent) && EntryResource::getEloquentQuery()->where('site', Site::current()->handle)->whereKey($parent)->exists(), 404);
        $this->parent = $parent;
    }

    protected function getHeaderActions(): array
    {
        return [
            // Created here, it sits under the Parent shown, which takes `reorder` (ADR 0014).
            CreateAction::make()
                ->url(fn (): string => EntryResource::getUrl('create', array_filter(['parent' => $this->parent])))
                ->visible(fn (): bool => $this->parent === null || EntryResource::can('reorder')),
        ];
    }

    /**
     * The way back up: the type's list, then each Parent, the one shown last and unlinked.
     *
     * @return array<int|string, string>
     */
    public function getBreadcrumbs(): array
    {
        if ($this->parent === null) {
            return parent::getBreadcrumbs();
        }

        $ids = array_reverse(Ancestors::ids($this->parent));
        $titles = EntryResource::pickerTitles($ids, $this);
        $breadcrumbs = [EntryResource::getUrl() => EntryResource::getPluralModelLabel()];

        foreach ($ids as $id) {
            $breadcrumbs[$id === $this->parent ? 0 : EntryResource::getUrl(parameters: ['parent' => $id])] = $titles[$id] ?? __('kami::admin.fields.untitled');
        }

        return $breadcrumbs;
    }

    /**
     * Through the core's action, which writes the Site's whole order; Filament's own would write only the rows given, from 1. Its keyboard buttons come here too.
     *
     * @param  array<int|string>  $order
     */
    #[Exposed]
    #[\Override]
    public function reorderTable(array $order, int | string | null $draggedRecordKey = null): void
    {
        if (! $this->getTable()->isReorderable()) {
            return;
        }

        resolve(ReorderEntries::class)->handle(EntryResource::type(), array_map(strval(...), $order), $this->parent);

        $this->flushCachedTableRecords();
    }

    /**
     * Filament sorts by the reorder column alone: tied Positions need the same order as `ordered()`.
     *
     * @param  Builder<Entry>  $query
     * @return Builder<Entry>
     */
    #[\Override]
    protected function applySortingToTableQuery(Builder $query): Builder
    {
        return $this->isTableReordering() && $query instanceof EntryBuilder ? $query->ordered() : parent::applySortingToTableQuery($query);
    }
}
