<?php

declare(strict_types=1);

namespace Psyao\Kami\Users\Internal;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * Filament's policy methods mapped to the Media library's Permissions (ADR 0014).
 *
 * @internal
 */
final class MediaPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return Gate::forUser($user)->allows('kami.media.view');
    }

    public function create(Authenticatable $user): bool
    {
        return Gate::forUser($user)->allows('kami.media.create');
    }
}
