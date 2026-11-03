<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Tables\Columns\Column;
use Filament\Tables\Filters\BaseFilter;
use Psyao\Kami\Admin\Internal\EntryResource;

/**
 * A Content Type's table in the admin, registered with `KamiPlugin::make()->tables([...])`. Extend it to choose the columns, filters and actions; the level, the manual order and their Permissions stay Kami's.
 */
class EntryTable
{
    /** @return array<Column> */
    public function columns(): array
    {
        return EntryResource::columns();
    }

    /**
     * The Trash filter.
     *
     * @return array<BaseFilter>
     */
    public function filters(): array
    {
        return EntryResource::filters();
    }

    /**
     * Each row's actions: Edit, Children on a hierarchical type, Trash, and Restore in the Trash.
     *
     * @return array<Action|ActionGroup>
     */
    public function recordActions(): array
    {
        return EntryResource::recordActions();
    }

    /**
     * The actions above the table, bulk ones included.
     *
     * @return array<Action|ActionGroup>
     */
    public function toolbarActions(): array
    {
        return [];
    }
}
