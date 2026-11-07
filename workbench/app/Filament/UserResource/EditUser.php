<?php

declare(strict_types=1);

namespace Workbench\App\Filament\UserResource;

use Filament\Resources\Pages\EditRecord;
use Workbench\App\Filament\UserResource;

class EditUser extends EditRecord
{
    #[\Override]
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [UserResource::deleteAction()];
    }
}
