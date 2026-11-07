<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Psyao\Kami\Audit\Actions\RecordAuditEvent;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Events\EntryDeleted;
use Psyao\Kami\Content\Internal\EntryTitles;
use Psyao\Kami\Content\Internal\Visibility;
use Psyao\Kami\Routing\Internal\Path;
use Psyao\Kami\Sites\Internal\Site;

/**
 * Deletes an Entry from the Trash for good, with its Translations, Field values, References and Preview links. Its addresses, in every Locale, become 301s to a replacement Entry, or 410s, so none falls silently to a 404 (ADR 0008). It does not authorise: the caller checks the actor's `force-delete` Permission first (ADR 0011).
 */
final class DeleteEntry
{
    /**
     * @param  Entry  $entry  A trashed Entry, read with `withTrashed()`.
     * @param  Entry|null  $replacement  Where its addresses send visitors, at that Entry's current URL in the same Locale; null answers 410.
     * @param  Authenticatable|null  $actor  Null for imports and the CLI.
     *
     * @throws ValidationException On `entry`: a Parent with children, which it names.
     */
    public function handle(Entry $entry, ?Entry $replacement = null, ?Authenticatable $actor = null): void
    {
        if (! $entry->trashed()) {
            throw new InvalidArgumentException("The Entry [{$entry->id}] is not in the Trash.");
        }

        if ($replacement?->id === $entry->id) {
            throw new InvalidArgumentException("The Entry [{$entry->id}] cannot replace itself.");
        }

        // Every child, any outside the Trash too, as `parent_id` restricts: a child saved under a trashed Parent (see `TrashEntry`) would fail the delete.
        // ponytail: read with no lock, so a child saved meanwhile reaches the caller as the foreign key's `QueryException`; V1 adds a lock on the type's tree if a friction shows it.
        $children = Entry::query()->withUnpublished()->withTrashed()->where('parent_id', $entry->id)->ordered();

        if ($children->exists()) {
            throw ValidationException::withMessages(['entry' => __('kami::admin.delete.children', ['children' => EntryTitles::list($children)])]);
        }

        DB::transaction(function () use ($entry, $replacement, $actor): void {
            Path::query()->where('entry_id', $entry->id)->update($replacement instanceof Entry ? ['status' => 301, 'entry_id' => $replacement->id] : ['status' => 410, 'entry_id' => null]);
            $entry->forceDelete();
            resolve(RecordAuditEvent::class)->handle('kami.entries.force-deleted', $entry, actor: $actor);
        });

        DB::afterCommit(fn () => Visibility::writeNextChange(Site::current()));
        event(new EntryDeleted($entry->id, $entry->type, $entry->site, $replacement?->id, $actor));
    }
}
