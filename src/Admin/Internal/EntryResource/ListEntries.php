<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal\EntryResource;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Components\Attributes\Exposed;
use Illuminate\Database\Eloquent\Builder;
use Psyao\Kami\Admin\Internal\EntryResource;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Internal\EntryBuilder;
use Psyao\Kami\Content\Internal\ReorderEntries;

/** @internal */
final class ListEntries extends ListRecords
{
    #[\Override]
    protected static string $resource = EntryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
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

        resolve(ReorderEntries::class)->handle(EntryResource::type(), array_map(strval(...), $order));

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
