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

    /** @return array<BaseFilter> */
    public function filters(): array
    {
        return [];
    }

    /**
     * Each row's actions: Edit, and Children on a hierarchical type.
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
