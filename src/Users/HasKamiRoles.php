<?php

declare(strict_types=1);

namespace Psyao\Kami\Users;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Psyao\Kami\Audit\Actions\RecordAuditEvent;
use Psyao\Kami\Users\Internal\LockoutGuard;

/**
 * `KamiUser` for an Eloquent User model: its Roles are its rows in `kami_role_user`.
 *
 * Its model hooks record a User created or deleted in the Audit log, and refuse to delete the last User holding `kami.users.manage`. A query-builder delete (`User::query()->delete()`) skips them, as it skips every Eloquent event.
 *
 * @phpstan-require-extends Model
 */
trait HasKamiRoles
{
    /** @var list<string>|null Read once per model instance, as the Gate asks for each Permission. */
    private ?array $kamiRoleHandles = null;

    /**
     * @internal
     *
     * Model hooks, Kami's only door to a model the host owns (ADR 0022). Before `kami:install` has migrated, they do nothing, so the host's Users work meanwhile.
     */
    public static function bootHasKamiRoles(): void
    {
        static::created(function (Model $user): void {
            if (Schema::hasTable('kami_audit_events')) {
                resolve(RecordAuditEvent::class)->handle('kami.users.created', $user, actor: Auth::user());
            }
        });

        // ponytail: `delete()` opens no transaction, so the lock holds only inside the caller's: two bare `delete()` calls on the last two managers at once may both pass.
        // A Users screen deletes inside one (`DeleteAction::databaseTransaction()`), as the guide says; overriding the host's `delete()` to open one waits for a site that needs it (V1).
        static::deleting(function (Model $user): void {
            if (Schema::hasTable('kami_audit_events') && LockoutGuard::isLastManager($user)) {
                throw LockoutGuard::refuse($user, 'delete', Auth::user());
            }
        });

        static::deleted(function (Model $user): void {
            if (Schema::hasTable('kami_audit_events')) {
                resolve(RecordAuditEvent::class)->handle('kami.users.deleted', $user, actor: Auth::user());
            }
        });
    }

    /** @return list<string> */
    public function kamiRoles(): array
    {
        return $this->kamiRoleHandles ??= array_values(array_filter(
            DB::table('kami_role_user')->where('user_id', $this->getKey())->pluck('role')->all(),
            is_string(...),
        ));
    }

    /** @internal Drops the Roles read, once `AssignRoles` has changed them. */
    public function forgetKamiRoles(): void
    {
        $this->kamiRoleHandles = null;
    }
}
