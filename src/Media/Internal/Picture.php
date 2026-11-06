<?php

declare(strict_types=1);

namespace Psyao\Kami\Media\Internal;

use InvalidArgumentException;
use Psyao\Kami\Media\Media;
use Psyao\Kami\Sites\Internal\Site;

/**
 * What `<x-kami::image>` prints for a Media item in an Image Preset, read from what is loaded: no query.
 *
 * @internal
 */
final readonly class Picture
{
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
     * Null for no Media item, and for what is not a raster image.
     *
     * @throws InvalidArgumentException For a preset not declared.
     */
    public static function of(?Media $media, string $preset, ?string $sizes = null): ?self
    {
        // ponytail: an SVG renders nothing until #300 gives it the URL of its file, then a plain `<img>` of it.
        if (! $media instanceof Media || ! Conversion::isConvertible($media)) {
            return null;
        }

        $presets = resolve(ImagePresets::class);
        $declared = $presets->find($preset) ?? throw new InvalidArgumentException("No Image Preset [{$preset}] is declared.");
        [$widest, $tallest] = Conversion::cropped($declared, $media);
        /** @var non-empty-list<int> $widths A preset declares one at least. */
        $widths = $presets->widths($declared, $media);
        // Each declared width a file is generated at, never upscaled: one above the widest gives the widest. None fits: the smallest alone.
        $shown = array_filter(array_combine($widths, $widths), fn (int $width): bool => $width <= $widest) ?: [min($widths) => $widest];
        $srcset = fn (string $format): string => implode(', ', array_map(
            fn (int $generated, int $shownAt): string => $media->url($preset, $generated, $format) . " {$shownAt}w",
            array_keys($shown),
            $shown,
        ));
        $fallback = Conversion::fallbackFormat($media);
        $sources = [];

        // The fallback format comes last, and goes on the `<img>`.
        foreach (array_slice($presets->formats($declared, $media), 0, -1) as $format) {
            $sources["image/{$format}"] = $srcset($format);
        }

        $width = max($shown);
        $alt = $media->isDecorative() ? '' : (string) $media->alt();
        $locale = $media->altLocale();

        return new self(
            $sources,
            $media->url($preset, array_key_last($shown), $fallback),
            $srcset($fallback),
            $sizes ?? $declared->sizes,
            $width,
            max(1, (int) round($width * $tallest / $widest)),
            $alt,
            $alt !== '' && $locale !== Site::current()->currentLocale() ? $locale : null,
        );
    }
}
