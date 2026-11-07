<?php

declare(strict_types=1);

namespace Psyao\Kami\Users\Internal;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Psyao\Kami\Users\KamiUser;

/**
 * Finds the host's Users by email for the CLI, which grants Roles on it (ADR 0001, step 7): `kami:install` and `kami:user:grant`.
 *
 * @internal
 */
final class UsersByEmail
{
    /** Checked before the lookup: a grant must not rest on a value that is no email, whatever the table holds. */
    public const array RULES = ['bail', 'required', 'string', 'email', 'max:255'];

    /**
     * Every User whose email matches, compared case-insensitively: a caller refuses several, even when one spelling is exact.
     *
     * @return Collection<int, Model&KamiUser>
     */
    public static function find(string $email): Collection
    {
        // kami_role_user points at this model (its migration), whichever guard the panel signs in with.
        // ponytail: a KamiUser because Doctor::blocking() requires it of the panel's guard model, assumed to be this one; #343 fails kami:doctor when it is not.
        /** @var class-string<Model&KamiUser> $users */
        $users = config('auth.providers.users.model');
        $query = $users::query();

        // lower() finds every spelling on every engine. No whereLike(): `_` is valid in an email and would be a wildcard.
        // The PHP check drops what the engine matches beyond ASCII case: MySQL's default collation ignores accents, so gràce@ would find grace@.
        // ponytail: outside ASCII an address matches exactly, whatever the engine could fold; V1 lifts it if a site needs it.
        return $query
            // @phpstan-ignore argument.type (the only non-literal part is the grammar's quoting of a constant column name; the email is bound)
            ->whereRaw('lower(' . $query->getGrammar()->wrap('email') . ') = lower(?)', [$email])
            ->get()
            ->filter(fn (Model $user): bool => is_string($stored = $user->getAttribute('email')) && strtolower($stored) === strtolower($email))
            ->values();
    }

    /**
     * The stored addresses of several matches, for the refusal, without control characters a terminal would act on.
     *
     * @param  Collection<int, Model&KamiUser>  $matches
     */
    public static function listed(Collection $matches): string
    {
        $stored = $matches->pluck('email')->implode(', ');

        return preg_replace('/\p{C}/u', '', $stored) ?? $stored;
    }
}
