<?php

declare(strict_types=1);

namespace Workbench\App\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;

/** The Users screen, for a User holding `kami.users.manage` only: an editor cannot promote themselves. Any signed-in model, as the panel may admit one that is not a Kami User. */
class UserPolicy
{
    public function viewAny(Authorizable $user): bool
    {
        return $user->can('kami.users.manage');
    }

    public function view(Authorizable $user): bool
    {
        return $user->can('kami.users.manage');
    }

    public function create(Authorizable $user): bool
    {
        return $user->can('kami.users.manage');
    }

    public function update(Authorizable $user): bool
    {
        return $user->can('kami.users.manage');
    }

    public function delete(Authorizable $user): bool
    {
        return $user->can('kami.users.manage');
    }

    public function deleteAny(Authorizable $user): bool
    {
        return $user->can('kami.users.manage');
    }
}
