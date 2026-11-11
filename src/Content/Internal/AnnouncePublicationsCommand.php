<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
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
 * The file moves after the reads and before the events are dispatched: a read that throws leaves its window to the next run, and a failing
 * listener is reported once, never replayed to the others at every run.
 *
 * ponytail: one query for the whole window; a window of days after a stalled scheduler loads its Translations at once, V1 chunks it if that ever weighs.
 *
 * @internal
 */
#[Description('Announce the Scheduled publications whose moment has come')]
#[Signature('kami:announce-publications')]
final class AnnouncePublicationsCommand extends Command
{
    /** The end of the last window announced, as a Unix timestamp; null before the first run. */
    public static function announcedUntil(): ?int
    {
        clearstatcache(true, $cursor = self::cursor());

        return ($at = @filemtime($cursor)) === false ? null : $at;
    }

    public function handle(): int
    {
        $now = Date::now()->startOfSecond();
        $from = Date::createFromTimestamp(self::announcedUntil() ?? $now->getTimestamp(), $now->getTimezone());

        // Saved while still Scheduled: a save at or after the moment announced it itself (`SaveEntry`).
        $translations = Translation::query()
            ->where('status', Translation::PUBLISHED)
            ->where('published_at', '>', $from)
            ->where('published_at', '<=', $now)
            ->whereColumn('updated_at', '<', 'published_at')
            ->whereExists(Visibility::outOfTrash(...))
            ->oldest('published_at')
            ->get(['entry_id', 'locale']);

        $entries = Entry::query()->withUnpublished()->findMany($translations->pluck('entry_id')->unique())->keyBy('id');
        $this->touchCursor($now->getTimestamp());
        $failed = 0;

        foreach ($translations as $translation) {
            if (($entry = $entries->get($translation->entry_id)) instanceof Entry) {
                rescue(fn () => event(new EntryPublished($entry, $translation->locale, true, null)), function () use (&$failed): void {
                    $failed++;
                });
            }
        }

        if ($translations->isNotEmpty()) {
            Sitemap::markStale();
        }

        $this->components->info("Announced {$translations->count()} " . Str::plural('publication', $translations->count()) . '.');

        if ($failed > 0) {
            $this->components->error("{$failed} " . Str::plural('announcement', $failed) . ' failed in a listener, reported to the exception handler.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private static function cursor(): string
    {
        return storage_path('framework/kami/announced-at');
    }

    private function touchCursor(int $at): void
    {
        File::ensureDirectoryExists(dirname($cursor = self::cursor()));

        if (! touch($cursor, $at)) {
            throw new RuntimeException("[{$cursor}] could not be written.");
        }
    }
}
