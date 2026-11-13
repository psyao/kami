<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal;

use Filament\Actions\Action;
use Filament\Auth\Pages\PasswordReset\ResetPassword as FilamentResetPassword;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Locked;
use SensitiveParameter;

/**
 * Filament's reset page, reading as setting a password when opened from a `SetPasswordLink`: its User never had one to reset.
 *
 * @internal
 */
final class ResetPassword extends FilamentResetPassword
{
    /** The query parameter `SetPasswordLink` adds, under the route's signature. */
    public const string SETS_PASSWORD = 'set';

    #[Locked]
    public bool $setsPassword = false;

    #[Unexposed]
    public function mount(?string $email = null, #[SensitiveParameter] ?string $token = null): void
    {
        parent::mount($email, $token);

        // The route's `signed` middleware has checked the parameter before the page mounts.
        $this->setsPassword = request()->boolean(self::SETS_PASSWORD);
    }

    #[Unexposed]
    public function getTitle(): string | Htmlable
    {
        return $this->setsPassword ? (string) __('kami::admin.set_password.title') : parent::getTitle();
    }

    #[Unexposed]
    public function getHeading(): string | Htmlable | null
    {
        return $this->setsPassword ? (string) __('kami::admin.set_password.title') : parent::getHeading();
    }

    #[Unexposed]
    public function getResetPasswordFormAction(): Action
    {
        $action = parent::getResetPasswordFormAction();

        return $this->setsPassword ? $action->label(__('kami::admin.set_password.submit')) : $action;
    }
}
