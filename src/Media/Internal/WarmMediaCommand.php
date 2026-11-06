<?php

declare(strict_types=1);

namespace Psyao\Kami\Media\Internal;

use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Internal\References;
use Psyao\Kami\Media\ImagePreset;
use Psyao\Kami\Media\Media;
use Throwable;

/**
 * Writes the files a Media item is served as before any visitor asks, for a migration or an import (ADR 0017). Each file
 * already written is skipped, so a run cut short by a time limit resumes where it stopped when started again.
 *
 * @internal
 */
#[Description('Generate the Conversions of every Media item, or of those an Entry uses, skipping the files already written')]
#[Signature('kami:media:warm
    {--preset=* : Only these Image Presets; file for a document\'s or an SVG\'s own file}
    {--entry= : Only the Media items this Entry uses, by its id}')]
final class WarmMediaCommand extends Command
{
    private Filesystem $disk;

    private int $written = 0;

    private int $existing = 0;

    /** @var list<string> */
    private array $failures = [];

    public function handle(ImagePresets $registry): int
    {
        /** @var list<string> $handles */
        $handles = array_values(array_unique($this->option('preset')));
        $presets = $handles === [] ? array_values($registry->all()) : [];

        foreach (array_diff($handles, [ImagePresets::FILE]) as $handle) {
            $preset = $registry->find($handle);

            if (! $preset instanceof ImagePreset) {
                $this->components->error("No Image Preset [{$handle}] is declared.");

                return self::FAILURE;
            }

            $presets[] = $preset;
        }

        $entry = $this->option('entry');

        if (is_string($entry) && ! Entry::query()->withUnpublished()->withTrashed()->whereKey($entry)->exists()) {
            $this->components->error("No Entry [{$entry}] exists.");

            return self::FAILURE;
        }

        $query = is_string($entry) ? References::mediaOf($entry) : Media::query();

        // Conversions read no alt text.
        $query->without('translations');
        $count = $query->count();

        if ($count === 0) {
            $this->components->info('Nothing to warm.');

            return self::SUCCESS;
        }

        // Artisan keeps one instance of a command: a second run in the same process starts from zero.
        [$this->disk, $this->written, $this->existing, $this->failures] = [Storage::disk(Config::string('kami.media.public_disk')), 0, 0, []];
        $files = $handles === [] || in_array(ImagePresets::FILE, $handles, true);
        $bar = $this->output->createProgressBar($count);

        // In chunks, so a large library never sits in memory.
        foreach ($query->lazyById(100) as $media) {
            if (Conversion::isConvertible($media)) {
                foreach (Conversion::offered($media, $presets) as $conversion) {
                    $this->warm($media, "{$conversion->preset->handle} {$conversion->width} {$conversion->format}", $conversion->path(), $conversion->write(...));
                }
            } elseif ($files) {
                $this->warm($media, ImagePresets::FILE, Conversion::filePath($media), fn (): ?string => Conversion::writeFile($media));
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        foreach ($this->failures as $failure) {
            $this->components->error($failure);
        }

        $summary = "{$count} " . Str::plural('Media item', $count) . ": {$this->written} files written, {$this->existing} already there"
            . ($this->failures === [] ? '.' : ', ' . count($this->failures) . ' failed.');
        $this->failures === [] ? $this->components->info($summary) : $this->components->warn($summary);

        return $this->failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @param Closure(): ?string $write Null when the Media item was replaced meanwhile, which writes nothing. */
    private function warm(Media $media, string $what, string $path, Closure $write): void
    {
        if ($this->disk->exists($path)) {
            $this->existing++;

            return;
        }

        // One failure is reported and the others go on, as the web falls back to another format for it.
        try {
            if ($write() !== null) {
                $this->written++;
            }
        } catch (Throwable $throwable) {
            report($throwable);
            $this->failures[] = "Media [{$media->id}] {$what}: {$throwable->getMessage()}";
        }
    }
}
