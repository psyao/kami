<?php

declare(strict_types=1);

namespace Psyao\Kami\Media\Internal;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Image;

/**
 * The formats offered before the fallback one, each with the Image driver that really encodes it on this host (ADR 0017):
 * a library can list a format it fails to write, so `queryFormats()` and `gd_info()` are not asked.
 *
 * @internal
 */
final class ImageFormats
{
    /** By extension, with their name, in the order a `<picture>` offers them, smallest first. */
    public const array FORMATS = ['avif' => 'AVIF', 'webp' => 'WebP'];

    /** A 1 × 1 PNG, as small as an encode can be. */
    private const string PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /**
     * Cached for a day per SAPI, as the command line and the web may load different extensions; `kami.media.avif` is read
     * each time, so switching it needs no cache clear.
     *
     * @return array<string, string> The driver per format, in offering order.
     */
    public function available(): array
    {
        $tested = Cache::remember('kami-media:formats:' . PHP_SAPI, now()->addDay(), $this->probe(...));

        return Config::boolean('kami.media.avif') ? $tested : array_diff_key($tested, ['avif' => true]);
    }

    /**
     * Runs the encode test now, `images.default` first, then the other driver.
     *
     * @return array<string, string> The driver per format, in offering order.
     */
    public function probe(): array
    {
        $drivers = Config::string('images.default', 'gd') === 'imagick' ? ['imagick', 'gd'] : ['gd', 'imagick'];
        $found = [];

        foreach (array_keys(self::FORMATS) as $format) {
            foreach ($drivers as $driver) {
                if ($this->encodes($driver, $format)) {
                    $found[$format] = $driver;

                    break;
                }
            }
        }

        return $found;
    }

    /** Written in that format, then read back by the same driver. A missing extension or encoder throws, read as a no. */
    private function encodes(string $driver, string $format): bool
    {
        return rescue(function () use ($driver, $format): bool {
            $encoded = Image::fromBytes(base64_decode(self::PIXEL))->using($driver)->toFormat($format)->toBytes();

            // PHP's own reader, not libmagic, which may not know AVIF yet.
            return ((getimagesizefromstring($encoded) ?: [])['mime'] ?? null) === "image/{$format}"
                && Image::fromBytes($encoded)->using($driver)->toPng()->dimensions() === [1, 1];
        }, false, report: false);
    }
}
