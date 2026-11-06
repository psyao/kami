<?php

declare(strict_types=1);

namespace Psyao\Kami\Media\Internal;

use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Psyao\Kami\Media\ImagePreset;
use Psyao\Kami\Media\Media;

/**
 * Kami's Image Presets and those listed in `kami.media.presets`, validated when first read, as Content Types are: not at boot,
 * so an invalid declaration never breaks the Artisan command that installs its fix.
 *
 * @internal
 */
final class ImagePresets
{
    /** The admin's thumbnails, generated after an upload. */
    public const string ADMIN_THUMB = 'admin-thumb';

    /** `original` has one width, the original's own. */
    public const string ORIGINAL = 'original';

    /** Not a preset: a document's or an SVG's own file, served unchanged. Reserved, so `url('file')` never means another. */
    public const string FILE = 'file';

    /** Read by link previews, many of which read JPEG and PNG only: the fallback format alone. */
    public const string SOCIAL = 'kami-social';

    /** @var array<mixed>|null The `kami.media.presets` last found valid. */
    private ?array $validated = null;

    /** @var array<string, ImagePreset> */
    private array $presets = [];

    public function find(string $handle): ?ImagePreset
    {
        return $this->all()[$handle] ?? null;
    }

    /**
     * The widths a Media item's Conversions of the preset are generated at.
     *
     * @return list<int>
     */
    public function widths(ImagePreset $preset, Media $media): array
    {
        return $preset->handle === self::ORIGINAL ? [(int) $media->width] : $preset->widths;
    }

    /**
     * The formats a Media item's Conversions of the preset are offered in, in a `<picture>`'s order, the fallback last.
     *
     * @return list<string>
     */
    public function formats(ImagePreset $preset, Media $media): array
    {
        $fallback = Conversion::fallbackFormat($media);

        return $preset->handle === self::SOCIAL ? [$fallback] : [...array_keys(resolve(ImageFormats::class)->available()), $fallback];
    }

    /** @return array<string, ImagePreset> Keyed by handle. */
    private function all(): array
    {
        $declared = Config::array('kami.media.presets');

        if ($declared !== $this->validated) {
            $this->presets = $this->validate($declared);
            $this->validated = $declared;
        }

        return $this->presets;
    }

    /**
     * @param  array<mixed>  $declared
     * @return array<string, ImagePreset>
     */
    private function validate(array $declared): array
    {
        $presets = array_column([
            ImagePreset::make(self::ADMIN_THUMB)->ratio(1, 1)->widths([320]),
            ImagePreset::make(self::ORIGINAL),
            ImagePreset::make(self::SOCIAL)->ratio(1.91, 1)->widths([1200]),
        ], null, 'handle');

        $shipped = [...array_keys($presets), self::FILE];

        foreach ($declared as $preset) {
            if (! $preset instanceof ImagePreset) {
                throw new InvalidArgumentException('Every entry of kami.media.presets is an ' . ImagePreset::class . '.');
            }

            $name = "Image Preset \"{$preset->handle}\"";

            if (preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $preset->handle) !== 1) {
                throw new InvalidArgumentException("{$name}: a handle is lowercase letters and digits, words joined by hyphens, starting with a letter.");
            }

            if (isset($presets[$preset->handle]) || $preset->handle === self::FILE) {
                throw new InvalidArgumentException(in_array($preset->handle, $shipped, true)
                    ? "{$name}: the handle is one of Kami's own presets; rename yours."
                    : "{$name}: the handle appears twice.");
            }

            if ($preset->widths === []) {
                throw new InvalidArgumentException("{$name}: declare at least one width.");
            }

            foreach ($preset->widths as $width) {
                // Six digits at most, as the route reads them.
                if ($width < 1 || $width > 999_999) {
                    throw new InvalidArgumentException("{$name}: every width is between 1 and 999999 pixels.");
                }
            }

            if ($preset->ratio !== null && min($preset->ratio) <= 0) {
                throw new InvalidArgumentException("{$name}: both sides of the ratio are positive.");
            }

            $presets[$preset->handle] = $preset;
        }

        return $presets;
    }
}
