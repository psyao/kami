<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Psyao\Kami\Audit\Actions\RecordAuditEvent;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Events\EntryRestored;
use Psyao\Kami\Content\Internal\EntryTitles;

/**
 * Brings an Entry back from the Trash with its states and Paths as they were: a Published Translation is visible again at once. It does not authorise: the caller checks the actor's `restore` Permission first, and `publish` when a Translation is published (ADR 0011, ADR 0019).
 */
final class RestoreEntry
{
    /**
     * @param  Entry  $entry  A trashed Entry, read with `withTrashed()`.
     * @param  Authenticatable|null  $actor  Recorded as the Last modifier; null for imports and the CLI.
     *
     * @throws ValidationException On `entry`: its Parent is in the Trash, which it names.
     */
    public function handle(Entry $entry, ?Authenticatable $actor = null): void
    {
        if (! $entry->trashed()) {
            throw new InvalidArgumentException("The Entry [{$entry->id}] is not in the Trash.");
        }

        $parent = $entry->parent_id === null ? null : Entry::query()->withUnpublished()->onlyTrashed()->find($entry->parent_id);

        if ($parent instanceof Entry) {
            throw ValidationException::withMessages(['entry' => __('kami::admin.restore.parent', ['parent' => EntryTitles::of($parent)])]);
        }

        DB::transaction(function () use ($entry, $actor): void {
            $entry->forceFill([
                'updated_by' => $actor?->getAuthIdentifier(),
                'updated_at' => $entry->freshTimestamp(),
                'deleted_at' => null,
            ])->save();
            resolve(RecordAuditEvent::class)->handle('kami.entries.restored', $entry, actor: $actor);
        });

        event(new EntryRestored($entry, $actor));
    }
}
