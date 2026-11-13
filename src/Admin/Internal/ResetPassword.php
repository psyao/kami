<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal;

use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\PasswordResetResponse;
use Filament\Auth\Pages\PasswordReset\ResetPassword as FilamentResetPassword;
use Filament\Support\Components\Attributes\Exposed;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Lang;
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
        return $this->setsPassword ? $this->getTitle() : parent::getHeading();
    }

    #[Exposed]
    public function resetPassword(): ?PasswordResetResponse
    {
        if ($this->setsPassword) {
            // Filament titles its notification with the broker's status line, `passwords.reset`, and offers no other hook: this request reads its own.
            // Loaded first, as a line added to a group not yet loaded would hide the group's other lines.
            $locale = app()->getLocale();
            Lang::load('*', 'passwords', $locale);
            Lang::addLines(['passwords.reset' => (string) __('kami::admin.set_password.done')], $locale);
        }

        return parent::resetPassword();
    }

    #[Unexposed]
    public function getResetPasswordFormAction(): Action
    {
        $action = parent::getResetPasswordFormAction();

        return $this->setsPassword ? $action->label(__('kami::admin.set_password.submit')) : $action;
    }
}
