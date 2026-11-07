<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal;

use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as FilamentRequestPasswordReset;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Support\Components\Attributes\Exposed;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Password;
use LogicException;
use SensitiveParameter;

/**
 * Filament's reset request page, answering every address the same way: its notification and its timing say nothing of who has an account.
 *
 * @internal
 */
final class RequestPasswordReset extends FilamentRequestPasswordReset
{
    #[Exposed]
    public function request(): void
    {
        // Per IP, as Filament does: its message says nothing about the address typed.
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $credentials = $this->getCredentialsFromFormData($this->form->getState());
        // The page is served by a panel's route: there is always one.
        $panel = Filament::getCurrentOrDefaultPanel() ?? throw new LogicException('The reset page runs outside a panel.');

        // After the response (FastCGI finish under FPM), so a known address takes no longer to answer than an unknown one.
        defer(fn () => $this->sendLink($credentials, $panel));

        Notification::make()
            ->title(__('kami::admin.password_reset.sent'))
            ->body(__('kami::admin.password_reset.sent_body'))
            ->success()
            ->send();

        // Unlike Filament, the field keeps the address: the same for every address, and `AdminEntriesTest` reads any form fill in `Admin` as a model write.
    }

    /** @param  array<string, mixed>  $credentials */
    private function sendLink(array $credentials, Panel $panel): void
    {
        // Its status, sent, throttled or unknown, goes nowhere: the page has already answered.
        Password::broker($panel->getAuthPasswordBroker())->sendResetLink($credentials, function (CanResetPassword $user, #[SensitiveParameter] string $token) use ($panel): void {
            if ($user instanceof FilamentUser && ! $user->canAccessPanel($panel)) {
                return;
            }

            if (! method_exists($user, 'notifyNow')) {
                throw new LogicException('Model [' . $user::class . '] does not have a [notifyNow()] method.');
            }

            $notification = resolve(ResetPassword::class, ['token' => $token]);
            $notification->url = $panel->getResetPasswordUrl($token, $user);

            // Now, not queued as Filament's notification asks: a shared host runs no queue worker, and the page has already answered.
            $user->notifyNow($notification);

            event(new PasswordResetLinkSent($user));
        });
    }
}
