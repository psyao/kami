<?php

declare(strict_types=1);

namespace Psyao\Kami\Media\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Psyao\Kami\Audit\Actions\RecordAuditEvent;
use Psyao\Kami\Content\Internal\EntryTitles;
use Psyao\Kami\Content\Internal\References;
use Psyao\Kami\Media\Events\MediaDeleted;
use Psyao\Kami\Media\Internal\Conversion;
use Psyao\Kami\Media\Media;

/**
 * Deletes a Media item for good, with its Translations, its original and every file served from it; there is no Media trash. It refuses while any Entry uses it, one in the Trash too. It does not authorise: the caller checks the actor's `kami.media.delete` Permission first (ADR 0011).
 */
final class DeleteMedia
{
    /**
     * @param  Authenticatable|null  $actor  Null for imports and the CLI.
     *
     * @throws ValidationException On `media`: Entries use it, which it names, those in the Trash marked.
     */
    public function handle(Media $media, ?Authenticatable $actor = null): void
    {
        // ponytail: read with no lock, so an Entry saved with it meanwhile reaches the caller as the foreign key's `QueryException`; V1 adds a lock if a friction shows it.
        $sources = References::mediaSources($media->id);

        $count = $sources->count();
        if ($count > 0) {
            throw ValidationException::withMessages(['media' => trans_choice('kami::admin.media.delete.used', $count, ['entries' => EntryTitles::list($sources)])]);
        }

        // The Translations go with the row, by the foreign key.
        DB::transaction(function () use ($media, $actor): void {
            $media->delete();
            resolve(RecordAuditEvent::class)->handle('kami.media.deleted', $media, ['name' => $media->name], $actor);
        });

        // Once committed, so a rollback keeps what the row still points to; at once, not at the next prune, so nothing of it stays served (Privacy, ADR 0017).
        DB::afterCommit(function () use ($media): void {
            Storage::disk(Config::string('kami.media.originals_disk'))->delete($media->file);
            Conversion::forget($media);
        });

        event(new MediaDeleted($media->id, $media->site, $actor));
    }
}
