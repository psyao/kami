<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal;

use Closure;
use Filament\Auth\MultiFactor\MultiFactorChallenge;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use LogicException;

/**
 * Filament's "MFA required" middleware, for the Users holding `kami.users.manage` only: Filament decides whether MFA is required once for the panel, at boot, with no User.
 *
 * @internal
 */
final class RequireMfa
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = Filament::auth()->user();

        if ($user === null || ! AppAuthentication::isRequiredFor($user) || MultiFactorChallenge::make()->hasEnabledProviders($user)) {
            return $next($request);
        }

        // Kami's provider makes MFA required on the panel, so Filament routes its set-up page.
        return redirect()->guest(Filament::getSetUpRequiredMultiFactorAuthenticationUrl() ?? throw new LogicException('The panel has no MFA set-up page.'));
    }
}
