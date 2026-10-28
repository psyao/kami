<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal\EntryResource;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Psyao\Kami\Admin\Internal\EntryResource;

/** @internal */
final class ListEntries extends ListRecords
{
    #[\Override]
    protected static string $resource = EntryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
