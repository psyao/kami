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

        // ponytail: the fallback format only; #298 adds AVIF and WebP where a real encode test passes.
        if (! $declared instanceof ImagePreset || $width < 1 || ! self::isConvertible($media) || $format !== self::fallbackFormat($media) || ! in_array($width, $presets->widths($declared, $media), true)) {
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
     * ponytail: by MIME type, not by the image's own alpha channel, so a WebP photo falls back to a larger PNG; AVIF and WebP come first in `<picture>` (#298).
     */
    public static function fallbackFormat(Media $media): string
    {
        return $media->mime === 'image/jpeg' ? 'jpg' : 'png';
    }

    /** Changes with the original and with what shapes its pixels, so a cached Conversion is never stale; a width or format is in the URL already. */
    public function version(): string
    {
        return substr(hash('xxh3', $this->media->checksum . '|' . implode(':', $this->preset->ratio ?? [])), 0, 8);
    }

    /** On `kami.media.public_disk`, which `public/media` links to. */
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
     * @return string The file's local path.
     */
    public function write(): string
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

        return $disk->path($path);
    }

    /** Oriented, cropped around the centre, never upscaled, stripped of its metadata, then moved into place whole: a visitor never reads half a file. */
    private function generate(Filesystem $disk, string $path): void
    {
        $image = Image::fromBytes((string) Storage::disk(Config::string('kami.media.originals_disk'))->get($this->media->file))->orient();
        // Stored as decoded, so already oriented.
        [$width, $height] = [max(1, (int) $this->media->width), max(1, (int) $this->media->height)];

        if ($this->preset->ratio !== null) {
            [$ratioWidth, $ratioHeight] = $this->preset->ratio;
            [$cropWidth, $cropHeight] = $width * $ratioHeight > $height * $ratioWidth
                ? [max(1, (int) round($height * $ratioWidth / $ratioHeight)), $height]
                : [$width, max(1, (int) round($width * $ratioHeight / $ratioWidth))];
            $image = $image->crop($cropWidth, $cropHeight, intdiv($width - $cropWidth, 2), intdiv($height - $cropHeight, 2));
        }

        $temporary = "{$path}." . Str::random(8) . '.tmp';
        $disk->put($temporary, $image->scale($this->width)->transform(new StripMetadata)->toFormat($this->format)->toBytes())
            ?: throw new RuntimeException("The Conversion [{$path}] could not be written.");
        $disk->move($temporary, $path);
    }
}
