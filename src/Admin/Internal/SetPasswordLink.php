<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal;

use Filament\Panel;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Password;
use LogicException;

/**
 * A one-time link to set a User's password (ADR 0001): a token from the panel's password broker, opening the panel's reset page.
 * No password is chosen or sent by whoever creates the User; the token expires after the broker's `expire` minutes and is spent once used.
 *
 * @internal
 */
final class SetPasswordLink
{
    /** @return array{url: string, minutes: int} */
    public static function make(Panel $panel, CanResetPassword $user): array
    {
        $name = $panel->getAuthPasswordBroker() ?? Config::string('auth.defaults.passwords');
        $broker = Password::broker($name);

        if (! $broker instanceof PasswordBroker) {
            throw new LogicException("The {$name} password broker cannot create a token: it is not Laravel's.");
        }

        return [
            'url' => $panel->getResetPasswordUrl($broker->createToken($user), $user),
            'minutes' => Config::integer("auth.passwords.{$name}.expire"),
        ];
    }
}
