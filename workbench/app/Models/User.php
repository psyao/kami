<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Psyao\Kami\Users\HasKamiRoles;
use Psyao\Kami\Users\KamiUser;

/**
 * The host's User model, wired for Kami as the starter kit wires it.
 *
 * @property string $name
 * @property string $email
 * @property string $password
 */
class User extends Authenticatable implements KamiUser
{
    use HasKamiRoles;
}
