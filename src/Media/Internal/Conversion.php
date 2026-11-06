<?php

declare(strict_types=1);

namespace Psyao\Kami\Media\Internal;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psyao\Kami\Media\ImagePreset;
use Psyao\Kami\Media\Media;
use Psyao\Kami\Sites\Internal\Site;
use RuntimeException;

/**
 * One file a Media item can be shown as: an Image Preset at one of its widths, in one format (ADR 0017). The one place
 * a Conversion's URL and path are built, so a remote URL builder can replace it (V1).
 *
 * @internal
 */
final readonly class Conversion
{
    /** Within shared hosting's 60 s budget: a worker that dies frees its lock by then. */
    private const int LOCK_SECONDS = 60;

    /** @param positive-int $width */
    private function __construct(public Media $media, public ImagePreset $preset, public int $width, public string $format) {}

    /** A Conversion the Media item has, or null for any preset, width or format not declared for it. */
    public static function find(Media $media, string $preset, int $width, string $format): ?self
    {
        $presets = resolve(ImagePresets::class);
        $declared = $presets->find($preset);

        if (! $declared instanceof ImagePreset || $width < 1 || ! self::isConvertible($media) || ! in_array($width, $presets->widths($declared, $media), true) || ! in_array($format, $presets->formats($declared, $media), true)) {
            return null;
        }

        return new self($media, $declared, $width, $format);
    }

    /** A raster image: a document or an SVG is served as it is, never converted. */
    public static function isConvertible(Media $media): bool
    {
        return $media->isImage() && $media->mime !== 'image/svg+xml';
    }

    /**
     * What every browser reads: JPEG for a JPEG, PNG for anything else, which may hold transparency.
     *
     * ponytail: by MIME type, not by the image's own alpha channel, so an opaque WebP or AVIF original falls back to a larger PNG,
     * which only browsers reading neither format get; V1 records transparency in `SaveMedia` if that weight matters.
     */
    public static function fallbackFormat(Media $media): string
    {
        return $media->mime === 'image/jpeg' ? 'jpg' : 'png';
    }

    /** The same Conversion in the format every browser reads. */
    public function fallback(): self
    {
        return new self($this->media, $this->preset, $this->width, self::fallbackFormat($this->media));
    }

    /** Changes with the original and with what shapes its pixels, so a cached Conversion is never stale; a width or format is in the URL already. */
    public function version(): string
    {
        return self::versionOf($this->media, implode(':', $this->preset->ratio ?? []));
    }

    /** A document's or an SVG's own file, served unchanged under its slugged name: `media/{id}/{v}/{name}.{ext}`. */
    public static function filePath(Media $media): string
    {
        return "media/{$media->id}/" . self::versionOf($media, '') . "/{$media->name}." . pathinfo($media->file, PATHINFO_EXTENSION);
    }

    public static function fileUrl(Media $media): string
    {
        return rtrim(Site::current()->url, '/') . '/' . self::filePath($media);
    }

    /**
     * Copies a document's or an SVG's original to the public disk unless it is there, as a stream: a large PDF never sits in memory,
     * whatever disk holds it. No lock: two workers copy the same bytes, and each move is whole.
     *
     * @return string|null The file's local path; null when the Media item's file was replaced since it was read, which writes nothing.
     */
    public static function writeFile(Media $media): ?string
    {
        $disk = Storage::disk(Config::string('kami.media.public_disk'));
        $path = self::filePath($media);

        if ($disk->missing($path) && self::isCurrent($media)) {
            $stream = Storage::disk(Config::string('kami.media.originals_disk'))->readStream($media->file)
                ?? throw new RuntimeException("The original of Media [{$media->id}] could not be read.");
            $temporary = '.kami-tmp/' . Str::random(16);

            try {
                $disk->writeStream($temporary, $stream) ?: throw new RuntimeException("The file [{$path}] could not be written.");
            } finally {
                fclose($stream);
            }

            self::isCurrent($media) ? $disk->move($temporary, $path) : $disk->delete($temporary);
        }

        return $disk->exists($path) ? $disk->path($path) : null;
    }

    private static function versionOf(Media $media, string $shape): string
    {
        return substr(hash('xxh3', $media->checksum . '|' . $shape), 0, 8);
    }

    /** Deletes every Conversion of the Media item, of any version: each is generated again on its first request. */
    public static function forget(Media $media): void
    {
        Storage::disk(Config::string('kami.media.public_disk'))->deleteDirectory("media/{$media->id}");
    }

    /**
     * The size of the original once cropped to the preset's ratio, as Conversions are cut from it: the widest any can be.
     *
     * @return array{positive-int, positive-int}
     */
    public static function cropped(ImagePreset $preset, Media $media): array
    {
        // Stored as decoded, so already oriented.
        [$width, $height] = [max(1, (int) $media->width), max(1, (int) $media->height)];

        if ($preset->ratio === null) {
            return [$width, $height];
        }

        [$ratioWidth, $ratioHeight] = $preset->ratio;

        return $width * $ratioHeight > $height * $ratioWidth
            ? [max(1, (int) round($height * $ratioWidth / $ratioHeight)), $height]
            : [$width, max(1, (int) round($width * $ratioHeight / $ratioWidth))];
    }

    /** On `kami.media.public_disk`, which `kami:install` links `public/media` to: Apache serves the file once written. */
    public function path(): string
    {
        return "media/{$this->media->id}/{$this->version()}/{$this->preset->handle}-{$this->width}.{$this->format}";
    }

    public function url(): string
    {
        return rtrim(Site::current()->url, '/') . '/' . $this->path();
    }

    /**
     * Generates the file unless it exists, once across workers: a worker that waits for another's lock finds the file written.
     *
     * @return string|null The file's local path; null when the Media item's file was replaced since it was read, which writes nothing.
     */
    public function write(): ?string
    {
        $disk = Storage::disk(Config::string('kami.media.public_disk'));
        $path = $this->path();

        if ($disk->missing($path)) {
            Cache::lock("kami-media:{$path}", self::LOCK_SECONDS)->block(self::LOCK_SECONDS - 10, function () use ($disk, $path): void {
                if (! $disk->exists($path)) {
                    $this->generate($disk, $path);
                }
            });
        }

        return $disk->exists($path) ? $disk->path($path) : null;
    }

    /**
     * Oriented, cropped around the centre, never upscaled, stripped of its metadata, then moved into place whole: a visitor never reads half a file.
     * A failure throws before anything is written, so the next request retries.
     */
    private function generate(Filesystem $disk, string $path): void
    {
        // A replacement committed since this Media item was read has deleted its former original and this version's directory.
        if (! self::isCurrent($this->media)) {
            return;
        }

        $image = Image::fromBytes((string) Storage::disk(Config::string('kami.media.originals_disk'))->get($this->media->file));
        // AVIF and WebP through the driver that passed the encode test; the fallback format through `images.default`.
        $driver = resolve(ImageFormats::class)->available()[$this->format] ?? null;
        $image = ($driver === null ? $image : $image->using($driver))->orient();
        // Stored as decoded, so already oriented.
        [$width, $height] = [max(1, (int) $this->media->width), max(1, (int) $this->media->height)];
        [$cropWidth, $cropHeight] = self::cropped($this->preset, $this->media);

        if ([$cropWidth, $cropHeight] !== [$width, $height]) {
            $image = $image->crop($cropWidth, $cropHeight, intdiv($width - $cropWidth, 2), intdiv($height - $cropHeight, 2));
        }

        // Outside `media/`, so Apache never serves it half written, and on the same disk, so the move is a rename.
        $temporary = '.kami-tmp/' . Str::random(16);
        $disk->put($temporary, $image->scale($this->width)->transform(new StripMetadata)->toFormat($this->format)->toBytes())
            ?: throw new RuntimeException("The Conversion [{$path}] could not be written.");

        // Again, as generating takes time: moving now would serve the former picture at its former URL.
        // ponytail: a replacement committing between this check and the move still slips through; `kami:media:prune` (#308) clears it.
        if (! self::isCurrent($this->media)) {
            $disk->delete($temporary);

            return;
        }

        $disk->move($temporary, $path);
    }

    /**
     * Whether the Media item still holds the file it was read with.
     *
     * @phpstan-impure
     */
    private static function isCurrent(Media $media): bool
    {
        return Media::query()->whereKey($media->id)->toBase()->value('checksum') === $media->checksum;
    }
}
