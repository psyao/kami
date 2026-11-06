<?php

declare(strict_types=1);

namespace Psyao\Kami\Media\Internal;

use InvalidArgumentException;
use Psyao\Kami\Media\ImagePreset;
use Psyao\Kami\Media\Media;
use Psyao\Kami\Sites\Internal\Site;

/**
 * What `<x-kami::image>` prints for a Media item in an Image Preset, read from what is loaded: no query.
 *
 * @internal
 */
final readonly class Picture
{
    private const array TYPES = ['avif' => 'image/avif', 'webp' => 'image/webp'];

    /**
     * @param  array<string, string>  $sources  A `srcset` per MIME type, in the `<picture>`'s order, the fallback format left to the `<img>`.
     * @param  string  $alt  Empty for a decorative image and for one missing its alt text: never a filename.
     * @param  string|null  $lang  The Locale the alt text falls back to, when it is not the current one.
     */
    private function __construct(
        public array $sources,
        public string $src,
        public string $srcset,
        public ?string $sizes,
        public int $width,
        public int $height,
        public string $alt,
        public ?string $lang,
    ) {}

    /**
     * Null for what is not a raster image.
     *
     * @throws InvalidArgumentException For a preset not declared.
     */
    public static function of(Media $media, string $preset, ?string $sizes = null): ?self
    {
        // ponytail: an SVG renders nothing until #300 gives it the URL of its file, then a plain `<img>` of it.
        if (! Conversion::isConvertible($media)) {
            return null;
        }

        $presets = resolve(ImagePresets::class);
        $declared = $presets->find($preset) ?? throw new InvalidArgumentException("No Image Preset [{$preset}] is declared.");
        $original = (int) $media->width;
        /** @var non-empty-list<int> $widths A preset declares one at least. */
        $widths = $presets->widths($declared, $media);
        // Never upscaled: a width above the original's is generated at the original's.
        $fitting = array_filter($widths, fn (int $width): bool => $width <= $original) ?: [min($widths)];
        $srcset = fn (string $format): string => implode(', ', array_map(
            fn (int $width): string => $media->url($preset, $width, $format) . ' ' . min($width, $original) . 'w',
            $fitting,
        ));
        $fallback = Conversion::fallbackFormat($media);
        $sources = [];

        // The fallback format comes last, and goes on the `<img>`.
        foreach (array_slice($presets->formats($declared, $media), 0, -1) as $format) {
            $sources[self::TYPES[$format]] = $srcset($format);
        }

        $width = min(max($fitting), $original);
        [$alt, $lang] = self::alt($media);

        return new self(
            $sources,
            $media->url($preset, max($fitting), $fallback),
            $srcset($fallback),
            $sizes ?? $declared->sizes,
            $width,
            self::height($declared, $media, $width),
            $alt,
            $lang,
        );
    }

    private static function height(ImagePreset $preset, Media $media, int $width): int
    {
        [$ratioWidth, $ratioHeight] = $preset->ratio ?? [(int) $media->width, (int) $media->height];

        return max(1, (int) round($width * $ratioHeight / max(1, $ratioWidth)));
    }

    /** @return array{string, string|null} */
    private static function alt(Media $media): array
    {
        $alt = $media->isDecorative() ? '' : (string) $media->alt();
        $site = Site::current();
        $own = $media->translations->firstWhere('locale', $site->currentLocale())?->getAttribute('alt');

        return [$alt, $alt !== '' && (! is_string($own) || trim($own) === '') ? $site->defaultLocale() : null];
    }
}
