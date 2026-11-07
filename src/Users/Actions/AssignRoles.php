<?php

declare(strict_types=1);

namespace Psyao\Kami\Users\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Psyao\Kami\Audit\Actions\RecordAuditEvent;
use Psyao\Kami\Users\Events\RolesAssigned;
use Psyao\Kami\Users\Internal\LockoutGuard;
use Psyao\Kami\Users\Internal\Permissions;
use Psyao\Kami\Users\KamiUser;

/**
 * Gives a User exactly the Roles listed, whoever asks: the admin, a seeder, `kami:install`, `kami:user:grant`. Each Role granted or revoked is recorded in the Audit log, and `RolesAssigned` follows the commit. It does not authorise: the caller checks `kami.users.manage` first (ADR 0014).
 */
final class AssignRoles
{
    /**
     * @param  list<string>  $roles  Every Role the User should hold, by handle: a Role left out is revoked.
     * @param  Authenticatable|null  $actor  Who acted: null records `cli` in the console and `system` otherwise.
     *
     * @throws ValidationException On `roles`: a Role to grant that `config/kami.php` does not declare, or a change that would leave no User holding `kami.users.manage` while one did (the lockout guard, recorded as `kami.users.lockout-refused`).
     */
    public function handle(Model & KamiUser $user, array $roles, ?Authenticatable $actor = null): void
    {
        $roles = array_values(array_unique($roles));

        $changed = null;

        $refused = DB::transaction(function () use ($user, $roles, $actor, &$changed): bool {
            $assignments = DB::table('kami_role_user')->where('user_id', $user->getKey());
            $held = array_values(array_filter($assignments->clone()->pluck('role')->all(), is_string(...)));
            $granted = array_values(array_diff($roles, $held));
            $revoked = array_values(array_diff($held, $roles));

            // A Role the User already holds may stay undeclared: kami:doctor reports it, and granting a Role beside it must not fail.
            if (($undeclared = array_diff($granted, array_keys(Config::array('kami.roles', [])))) !== []) {
                throw ValidationException::withMessages(['roles' => __('kami::admin.roles.undeclared', ['roles' => implode(', ', $undeclared)])]);
            }

            if (array_intersect($roles, Permissions::rolesGranting('kami.users.manage')) === [] && LockoutGuard::isLastManager($user)) {
                return true;
            }

            $assignments->clone()->whereIn('role', $revoked)->delete();
            DB::table('kami_role_user')->insert(array_map(fn (string $role): array => ['id' => (string) Str::ulid(), 'user_id' => $user->getKey(), 'role' => $role], $granted));

            foreach ($granted as $role) {
                resolve(RecordAuditEvent::class)->handle('kami.roles.granted', $user, ['role' => $role], $actor);
            }

            foreach ($revoked as $role) {
                resolve(RecordAuditEvent::class)->handle('kami.roles.revoked', $user, ['role' => $role], $actor);
            }

            $changed = $granted === [] && $revoked === [] ? null : new RolesAssigned($user, $granted, $revoked, $actor);

            return false;
        });

        // HasKamiRoles reads the Roles once per instance.
        if (method_exists($user, 'forgetKamiRoles')) {
            $user->forgetKamiRoles();
        }

        if ($refused) {
            throw LockoutGuard::refuse($user, 'revoke', $actor);
        }

        if ($changed instanceof RolesAssigned) {
            event($changed);
        }
    }
}
