<?php

declare(strict_types=1);

namespace Psyao\Kami\Users\Internal;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Users\KamiUser;

/**
 * Kami's Permission vocabulary, one Gate each, granted by the Roles of `config/kami.php` (ADR 0014).
 *
 * @internal
 */
final class Permissions
{
    /** What a User may do with a Content Type's Entries in M2; ADR 0014's other actions join with their features. */
    private const array ENTRY_ACTIONS = ['view', 'create', 'update', 'publish'];

    /**
     * No `Gate::before`: it would answer the host's own abilities too.
     */
    public static function register(Gate $gate): void
    {
        foreach (self::all() as $permission) {
            $gate->define($permission, fn (Authenticatable $user): bool => $user instanceof KamiUser && self::granted($user, $permission));
        }

        $gate->policy(Entry::class, EntryPolicy::class);
    }

    /** @return list<string> */
    public static function all(): array
    {
        $entries = collect(resolve(ContentTypes::class)->all())
            ->flatMap(fn (ContentType $type): array => array_map(fn (string $action): string => "kami.entries.{$type->handle()}.{$action}", self::ENTRY_ACTIONS));

        return [...$entries, 'kami.users.manage'];
    }

    /**
     * The declared Roles whose Permissions, wildcards included, cover this one.
     *
     * @return list<string>
     */
    public static function rolesGranting(string $permission): array
    {
        // ponytail: wildcards matched per check, the same answer as expanding them at boot; expand once if a profile ever shows it.
        return array_map(strval(...), array_keys(array_filter(
            Config::array('kami.roles', []),
            fn (mixed $patterns): bool => is_array($patterns) && Str::is(array_filter($patterns, is_string(...)), $permission),
        )));
    }

    private static function granted(KamiUser $user, string $permission): bool
    {
        return array_intersect($user->kamiRoles(), self::rolesGranting($permission)) !== [];
    }
}
