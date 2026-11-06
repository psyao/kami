<?php

declare(strict_types=1);

namespace Psyao\Kami\Media\Internal;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psyao\Kami\Media\Media;

/**
 * Deletes the files on the public disk that no Media item serves any more (ADR 0017). Nothing visible depends on it: a
 * stale file is only disk used. It walks one Media item's directory at a time, so a run cut short is simply started again.
 * Files at the root of `media/`, such as the `.htaccess` `kami:install` writes, are never touched.
 *
 * @internal
 */
#[Description('Delete the Media files no longer served: former versions, removed presets, widths and formats, deleted items, interrupted writes')]
#[Signature('kami:media:prune')]
final class PruneMediaCommand extends Command
{
    /** Older than any write still running: a web request and a Conversion's lock last 60 s at most. */
    private const int LEFTOVER_SECONDS = 3600;

    public function handle(): int
    {
        $disk = Storage::disk(Config::string('kami.media.public_disk'));
        $deleted = 0;

        // What a worker killed between writing a file and moving it into place leaves.
        foreach ($disk->files('.kami-tmp') as $path) {
            if ($disk->lastModified($path) < now()->getTimestamp() - self::LEFTOVER_SECONDS) {
                $disk->delete($path);
                $deleted++;
            }
        }

        foreach (array_chunk($disk->directories('media'), 100) as $directories) {
            $items = Media::query()->without('translations')->whereKey(array_map(basename(...), $directories))->get()->keyBy('id');

            foreach ($directories as $directory) {
                $item = $items->get(basename($directory));
                // A deleted item keeps nothing.
                $kept = array_flip($item instanceof Media ? Conversion::kept($item) : []);

                foreach ($disk->allFiles($directory) as $path) {
                    if (! isset($kept[$path])) {
                        $disk->delete($path);
                        $deleted++;
                    }
                }

                foreach ([...$disk->directories($directory), $directory] as $emptied) {
                    if ($disk->allFiles($emptied) === []) {
                        $disk->deleteDirectory($emptied);
                    }
                }
            }
        }

        $this->components->info("Deleted {$deleted} " . Str::plural('file', $deleted) . '.');

        return self::SUCCESS;
    }
}
