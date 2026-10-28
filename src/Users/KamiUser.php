<?php

declare(strict_types=1);

namespace Psyao\Kami\Users;

/**
 * The host's User model, as Kami checks its Permissions: implemented with `HasKamiRoles` (ADR 0014).
 *
 * The model of the guard behind the panel carrying `KamiPlugin` implements it, or `kami:install` stops.
 */
interface KamiUser
{
    /**
     * The handles of the Roles the User holds; a Role not declared in `config/kami.php` grants nothing.
     *
     * Kami checks Permissions, never Roles: ask the Gate (`$user->can('kami.users.manage')`), not this list.
     *
     * @return list<string>
     */
    public function kamiRoles(): array;
}
