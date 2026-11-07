<?php

declare(strict_types=1);

namespace Psyao\Kami\Users\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Psyao\Kami\Users\KamiUser;

/**
 * A User's Roles changed through `AssignRoles`, once the write committed (ADR 0029). Not dispatched when the User already held exactly the Roles listed.
 */
final readonly class RolesAssigned implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<string>  $granted  The handles of the Roles the User gained.
     * @param  list<string>  $revoked  The handles of the Roles the User lost.
     * @param  Authenticatable|null  $actor  Null for seeders and the CLI.
     */
    public function __construct(
        public Model & KamiUser $user,
        public array $granted,
        public array $revoked,
        public ?Authenticatable $actor,
    ) {}
}
