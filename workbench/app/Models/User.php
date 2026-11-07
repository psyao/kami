<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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

    // A password reset link is a notification, as on Laravel's own User.
    use Notifiable;
}
