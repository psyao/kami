<?php

declare(strict_types=1);

namespace Workbench\App\Filament\UserResource;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Psyao\Kami\Admin\SetPasswordLink;
use Workbench\App\Filament\UserResource;
use Workbench\App\Models\User;

/** Nobody chooses the new User's password: the screen gives a one-time link, as `kami:install` prints, for them to set it. */
class CreateUser extends CreateRecord
{
    #[\Override]
    protected static string $resource = UserResource::class;

    /** The User, their Roles and the link's token, all or none. */
    #[\Override]
    protected ?bool $hasDatabaseTransactions = true;

    private ?Notification $link = null;

    /**
     * Unusable until the link sets one.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...$data, 'password' => Hash::make(Str::random(64))];
    }

    protected function afterCreate(): void
    {
        /** @var User $user */
        $user = $this->getRecord();
        ['url' => $url, 'minutes' => $minutes] = SetPasswordLink::make($user);

        $this->link = Notification::make()
            ->success()
            ->persistent()
            ->title(__('users.link.title', ['name' => $user->name]))
            ->body(__('users.link.body', ['url' => $url, 'minutes' => $minutes]))
            // The URL runs to some 230 characters: one click copies it, as Filament copies recovery codes.
            ->actions([
                Action::make('copy')
                    ->label(__('users.link.copy'))
                    ->alpineClickHandler('window.navigator.clipboard.writeText(' . Js::from($url) . ').then(() => $tooltip(' . Js::from(__('users.link.copied')) . ', { theme: $store.theme }))'),
            ]);
    }

    /** In place of Filament's "Created". */
    protected function getCreatedNotification(): ?Notification
    {
        return $this->link;
    }
}
