<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal;

use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication as FilamentAppAuthentication;
use Filament\Facades\Filament;
use Filament\Forms\Components\OneTimeCodeInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
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
                ? $this->skipCodeOnRecovery($action)->hidden(fn (): bool => ($user = Filament::auth()->user()) !== null && self::isRequiredFor($user))
                : $action,
            parent::getActions(),
        );
    }

    /**
     * Filament validates a filled `code` even once the recovery field shows, after the recovery rule has spent its code: a wrong code left there
     * would refuse disabling yet use the recovery code up (#578, as #384 on sign-in). A typed recovery code alone decides; with none, the `code` still disables.
     * The action reads no `code` from its data, so leaving it out changes nothing else.
     */
    private function skipCodeOnRecovery(Action $action): Action
    {
        // ponytail: while `filamentphp/filament` validates both fields; V1 drops it once Filament's `code` rule skips a recovery attempt.
        // Filament's schema is an array of components, adjusted in place: the action's own schema keeps the same objects.
        foreach ($action->getSchema(Schema::make())?->getComponents(withActions: false, withHidden: true) ?? [] as $component) {
            if ($component instanceof OneTimeCodeInput && $component->getName() === 'code') {
                $component
                    ->dehydrated(fn (Get $get): bool => $get('useRecoveryCode') !== true || blank($get('recoveryCode')))
                    ->validatedWhenNotDehydrated(false);
            }
        }

        return $action;
    }
}
