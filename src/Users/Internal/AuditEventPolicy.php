<?php

declare(strict_types=1);

namespace Psyao\Kami\Users\Internal;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * Reading the Audit log, and nothing else: no method grants creating, editing or deleting an event (ADR 0022).
 *
 * @internal
 */
final class AuditEventPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return Gate::forUser($user)->allows('kami.audit.view');
    }

    public function view(Authenticatable $user): bool
    {
        return Gate::forUser($user)->allows('kami.audit.view');
    }
}
