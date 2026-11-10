<?php

declare(strict_types=1);

namespace Psyao\Kami\Users\Internal;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * Reading and deleting Submissions, each under its own Permission; nothing creates or edits one in the admin (ADR 0014).
 *
 * @internal
 */
final class SubmissionPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return Gate::forUser($user)->allows('kami.form-submissions.view');
    }

    public function view(Authenticatable $user): bool
    {
        return Gate::forUser($user)->allows('kami.form-submissions.view');
    }

    /** For good, through `DeleteSubmissions`. */
    public function delete(Authenticatable $user): bool
    {
        return Gate::forUser($user)->allows('kami.form-submissions.delete');
    }

    public function deleteAny(Authenticatable $user): bool
    {
        return Gate::forUser($user)->allows('kami.form-submissions.delete');
    }
}
