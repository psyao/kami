<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Events\EntryPublished;
use Psyao\Kami\Seo\Internal\Sitemap;
use RuntimeException;

/**
 * Fires, every minute in `Kami::schedule()`, what an immediate publication fires for the Scheduled Translations whose moment came since its last run:
 * `EntryPublished` with `scheduled: true`, and a stale sitemap (ADR 0019). Visibility never waited for it, and the page cache turned stale at the
 * moment on its own (ADR 0021): it writes no row and starts no new Content version.
 *
 * Its window is `(last run, now]`, the last run being the `mtime` of `storage/framework/kami/announced-at`: `kami_task_runs` already holds this
 * run's start when the command begins, and a run by hand moves the file too. With no file, the first run starts its window at `now()`.
 * The file moves once the events are dispatched, so a run cut short announces again rather than never.
 *
 * ponytail: one query for the whole window; a window of days after a stalled scheduler loads its Translations at once, V1 chunks it if that ever weighs.
 *
 * @internal
 */
#[Description('Announce the Scheduled publications whose moment has come')]
#[Signature('kami:announce-publications')]
final class AnnouncePublicationsCommand extends Command
{
    public function handle(): int
    {
        $cursor = storage_path('framework/kami/announced-at');
        $now = Date::now()->startOfSecond();
        clearstatcache(true, $cursor);

        if (($last = @filemtime($cursor)) === false) {
            $this->move($cursor, $now->getTimestamp());
            $this->components->info('Announcing the Scheduled publications from now on.');

            return self::SUCCESS;
        }

        // Saved while still Scheduled: a save at or after the moment announced it itself.
        $translations = Translation::query()
            ->where('status', Translation::PUBLISHED)
            ->where('published_at', '>', Date::createFromTimestamp($last))
            ->where('published_at', '<=', $now)
            ->whereColumn('updated_at', '<', 'published_at')
            ->whereExists(fn (Builder $entries) => $entries->select(DB::raw(1))->from('kami_entries')->whereColumn('kami_entries.id', 'kami_translations.entry_id')->whereNull('deleted_at'))
            ->oldest('published_at')
            ->get(['entry_id', 'locale']);

        $entries = Entry::query()->withUnpublished()->findMany($translations->pluck('entry_id')->unique())->keyBy('id');

        foreach ($translations as $translation) {
            if (($entry = $entries->get($translation->entry_id)) instanceof Entry) {
                event(new EntryPublished($entry, $translation->locale, true, null));
            }
        }

        if ($translations->isNotEmpty()) {
            Sitemap::markStale();
        }

        $this->move($cursor, $now->getTimestamp());
        $this->components->info("Announced {$translations->count()} " . Str::plural('publication', $translations->count()) . '.');

        return self::SUCCESS;
    }

    private function move(string $cursor, int $at): void
    {
        File::ensureDirectoryExists(dirname($cursor));
        if (! touch($cursor, $at)) {
            throw new RuntimeException("[{$cursor}] could not be written.");
        }
    }
}
