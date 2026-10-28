<?php

declare(strict_types=1);

namespace Psyao\Kami\Users;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * `KamiUser` for an Eloquent User model: its Roles are its rows in `kami_role_user`.
 *
 * @phpstan-require-extends Model
 */
trait HasKamiRoles
{
    /** @var list<string>|null Read once per model instance, as the Gate asks for each Permission. */
    private ?array $kamiRoleHandles = null;

    /** @return list<string> */
    public function kamiRoles(): array
    {
        return $this->kamiRoleHandles ??= array_values(array_filter(
            DB::table('kami_role_user')->where('user_id', $this->getKey())->pluck('role')->all(),
            is_string(...),
        ));
    }
}
