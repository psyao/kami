<?php

declare(strict_types=1);

namespace Workbench\App\Filament\UserResource;

use Filament\Resources\Pages\EditRecord;
use Workbench\App\Filament\UserResource;

class EditUser extends EditRecord
{
    #[\Override]
    protected static string $resource = UserResource::class;

    /** Filament saves the Roles before the User: a User that then fails to save keeps its Roles unchanged. */
    #[\Override]
    protected ?bool $hasDatabaseTransactions = true;

    protected function getHeaderActions(): array
    {
        return [UserResource::deleteAction()];
    }
}
