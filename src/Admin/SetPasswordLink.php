<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin;

use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Password;
use LogicException;
use Psyao\Kami\Admin\Internal\ResetPassword;

/**
 * A one-time link to set a User's password (ADR 0001), as `kami:install` prints and a Users screen offers: a token from the panel's password broker, opening the panel's reset page.
 * No password is chosen or sent by whoever creates the User; the token expires after the broker's `expire` minutes and is spent once used.
 */
final class SetPasswordLink
{
    /**
     * @param  Panel|null  $panel  The panel whose reset page opens the link: the one serving the request by default.
     * @return array{url: string, minutes: int}
     *
     * @throws LogicException When the panel has no `->passwordReset()`.
     */
    public static function make(CanResetPassword $user, ?Panel $panel = null): array
    {
        $panel ??= Filament::getCurrentOrDefaultPanel() ?? throw new LogicException('No Filament panel to open the link in.');

        if (! $panel->hasPasswordReset()) {
            throw new LogicException("The {$panel->getId()} panel has no reset page to open the link: add ->passwordReset() to it.");
        }

        $name = $panel->getAuthPasswordBroker() ?? Config::string('auth.defaults.passwords');
        $broker = Password::broker($name);

        if (! $broker instanceof PasswordBroker) {
            throw new LogicException("The {$name} password broker cannot create a token: it is not Laravel's.");
        }

        return [
            // Under the route's signature: the reset page then reads as setting a password, not resetting one.
            'url' => $panel->getResetPasswordUrl($broker->createToken($user), $user, [ResetPassword::SETS_PASSWORD => 1]),
            'minutes' => Config::integer("auth.passwords.{$name}.expire"),
        ];
    }
}
