<?php

declare(strict_types=1);

namespace Psyao\Kami\Users\Internal;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Psyao\Kami\Audit\Actions\RecordAuditEvent;

/**
 * The lockout guard: the last User holding `kami.users.manage` neither loses it nor is deleted, so the site never locks itself out of its admin (ADR 0014).
 *
 * @internal
 */
final class LockoutGuard
{
    /**
     * Whether this User alone holds `kami.users.manage` among the Users the host's model still finds: a soft-deleted one keeps its rows, yet signs in no more.
     * Inside a transaction, the holders' rows stay locked until it ends, so two Users cannot take it from each other at once.
     */
    public static function isLastManager(Model $user): bool
    {
        // pluck, not count: PostgreSQL refuses FOR UPDATE with an aggregate.
        $holders = DB::table('kami_role_user')->whereIn('role', Permissions::rolesGranting('kami.users.manage'))->lockForUpdate()->pluck('user_id')->all();
        $id = fn (mixed $key): string => is_scalar($key) ? (string) $key : '';

        // Locked too: on MySQL a plain read could answer from a snapshot taken before a concurrent soft delete committed.
        return $user->newQuery()->whereKey($holders)->lockForUpdate()->pluck($user->getKeyName())->map($id)->all() === [$id($user->getKey())];
    }

    /**
     * Records the refusal, so an attempt to lock the site out shows in the Audit log, and records it again when a caller's transaction rolls it back with its own work.
     *
     * @param  'revoke'|'delete'  $operation
     */
    public static function refuse(Model $user, string $operation, ?Authenticatable $actor): ValidationException
    {
        $record = fn () => resolve(RecordAuditEvent::class)->handle('kami.users.lockout-refused', $user, ['operation' => $operation], $actor);
        $record();

        if (DB::transactionLevel() > 0) {
            DB::afterRollBack($record);
        }

        return ValidationException::withMessages($operation === 'revoke'
            ? ['roles' => __('kami::admin.roles.lockout')]
            : ['user' => __('kami::admin.roles.lockout_delete')]);
    }
}
