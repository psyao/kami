<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Events\EntryTrashed;
use Psyao\Kami\Content\Internal\EntryTitles;
use Psyao\Kami\Content\Internal\Visibility;
use Psyao\Kami\Content\Singleton;
use Psyao\Kami\Sites\Internal\Site;

/**
 * Moves an Entry to the Trash in all its Locales: it reads as absent everywhere and answers 404, its Paths stay reserved, and `RestoreEntry` brings it back as it was. It does not authorise: the caller checks the actor's `delete` Permission first (ADR 0011).
 */
final class TrashEntry
{
    /**
     * @param  Authenticatable|null  $actor  Recorded as the Last modifier; null for imports and the CLI.
     *
     * @throws ValidationException On `entry`: a Singleton or a Global Set, never trashed, or a Parent with children outside the Trash, which it names.
     */
    public function handle(Entry $entry, ?Authenticatable $actor = null): void
    {
        if ($entry->trashed()) {
            throw new InvalidArgumentException("The Entry [{$entry->id}] is already in the Trash.");
        }

        if ($entry->contentType() instanceof Singleton) {
            throw ValidationException::withMessages(['entry' => __('kami::admin.trash.singleton')]);
        }

        $children = Entry::query()->withUnpublished()->where('parent_id', $entry->id)->ordered();

        // ponytail: read with no lock, so a child saved meanwhile can end under a trashed Parent; V1 adds a lock on the type's tree if a friction shows it.
        if ($children->exists()) {
            throw ValidationException::withMessages(['entry' => __('kami::admin.trash.children', ['children' => EntryTitles::list($children)])]);
        }

        DB::transaction(fn () => $entry->forceFill([
            'updated_by' => $actor?->getAuthIdentifier(),
            'updated_at' => $now = $entry->freshTimestamp(),
            'deleted_at' => $now,
        ])->save());

        DB::afterCommit(fn () => Visibility::writeNextChange(Site::current()));
        event(new EntryTrashed($entry, $actor));
    }
}
