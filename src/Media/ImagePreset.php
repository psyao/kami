<?php

declare(strict_types=1);

namespace Psyao\Kami\Media;

/**
 * A named way of showing images, listed in `config/kami.php` under `media.presets` (ADR 0017).
 *
 * Its Conversions are generated at these widths only, cropped around the centre to its ratio when it has one, never upscaled.
 */
final class ImagePreset
{
    /** @var array{int|float, int|float}|null Width, then height; null keeps each image's own proportions. */
    public private(set) ?array $ratio = null;

    /** @var list<int> */
    public private(set) array $widths = [];

    /** The default `sizes` attribute of the image's `srcset`. */
    public private(set) ?string $sizes = null;

    private function __construct(public readonly string $handle) {}

    /** @param string $handle Lowercase kebab-case, as it appears in the Conversion's URL. */
    public static function make(string $handle): self
    {
        return new self($handle);
    }

    /** Crops every Conversion to this ratio, around the centre: `ratio(4, 3)`, `ratio(1.91, 1)`. */
    public function ratio(int | float $width, int | float $height): self
    {
        $this->ratio = [$width, $height];

        return $this;
    }

    /** @param list<int> $widths In pixels; a width above the original's gives the original's. */
    public function widths(array $widths): self
    {
        $this->widths = $widths;

        return $this;
    }

    public function sizes(string $sizes): self
    {
        $this->sizes = $sizes;

        return $this;
    }

    /**
     * @internal
     *
     * Rebuilds a preset from `config:cache`'s `var_export()`.
     *
     * @param  array{handle: string, ratio: array{int|float, int|float}|null, widths: list<int>, sizes: string|null}  $state
     */
    public static function __set_state(array $state): self
    {
        $preset = new self($state['handle']);
        $preset->ratio = $state['ratio'];
        $preset->widths = $state['widths'];
        $preset->sizes = $state['sizes'];

        return $preset;
    }
}
