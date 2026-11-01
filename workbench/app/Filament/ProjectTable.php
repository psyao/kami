<?php

declare(strict_types=1);

namespace Workbench\App\Filament;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Psyao\Kami\Admin\EntryTable;

/** A site's own table: the generated columns, a Field's column and a filter. */
final class ProjectTable extends EntryTable
{
    public function columns(): array
    {
        return [...parent::columns(), TextColumn::make('client')->label('Client')->default('No client')];
    }

    public function filters(): array
    {
        return [
            Filter::make('recent')
                ->label('Created this month')
                ->query(fn (Builder $query): Builder => $query->whereDate('created_at', '>=', now()->subMonth())),
        ];
    }
}
