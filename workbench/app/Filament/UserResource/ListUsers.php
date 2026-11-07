<?php

declare(strict_types=1);

namespace Workbench\App\Filament\UserResource;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Workbench\App\Filament\UserResource;

class ListUsers extends ListRecords
{
    #[\Override]
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
