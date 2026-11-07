<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal;

use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication as FilamentAppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * Filament's app authentication, which a User holding `kami.users.manage` cannot disable: Filament runs no hidden action.
 *
 * @internal
 */
final class AppAuthentication extends FilamentAppAuthentication
{
    /** Whether this User must set MFA up before reaching the admin (ADR 0014). */
    public static function isRequiredFor(Authenticatable $user): bool
    {
        return Gate::forUser($user)->allows('kami.users.manage');
    }

    /** @return array<Action> */
    public function getActions(): array
    {
        return array_map(
            fn (Action $action): Action => $action->getName() === 'disableAppAuthentication'
                ? $action->hidden(fn (): bool => ($user = Filament::auth()->user()) !== null && self::isRequiredFor($user))
                : $action,
            parent::getActions(),
        );
    }
}
