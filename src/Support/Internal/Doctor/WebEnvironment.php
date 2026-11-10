<?php

declare(strict_types=1);

namespace Psyao\Kami\Support\Internal\Doctor;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Psyao\Kami\Media\Internal\ImageFormats;

/**
 * What the web's PHP looks like, written by each web or visit Tick for `kami:doctor`, which runs on the command line and sees another PHP.
 *
 * @internal
 *
 * @phpstan-type Snapshot array{sapi: string, php: string, memory_limit: string, max_execution_time: int, disable_functions: list<string>, extensions: list<string>, image_formats: array<string>}
 */
final class WebEnvironment
{
    /**
     * Rewrites the file when the PHP serving this request differs from its content, and touches it otherwise, so its mtime is when the web was last seen.
     * The image formats come from `ImageFormats`' cache for this SAPI: a Tick runs no encode test of its own.
     */
    public static function capture(): void
    {
        $snapshot = (string) json_encode([
            'sapi' => PHP_SAPI,
            'php' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => (int) ini_get('max_execution_time'),
            'disable_functions' => array_values(array_filter(array_map(trim(...), explode(',', (string) ini_get('disable_functions'))))),
            'extensions' => array_map(strtolower(...), get_loaded_extensions()),
            'image_formats' => resolve(ImageFormats::class)->available(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (@file_get_contents($path = self::path()) === $snapshot) {
            touch($path);

            return;
        }

        File::ensureDirectoryExists(dirname($path));
        File::replace($path, $snapshot);
    }

    /**
     * The last snapshot with when it was last confirmed, or null while no web Tick wrote a readable one.
     *
     * @return array{snapshot: Snapshot, seen_at: int}|null
     */
    public static function last(): ?array
    {
        return rescue(function (): ?array {
            $path = self::path();
            clearstatcache(true, $path);

            if (! is_file($path)) {
                return null;
            }

            // A file of another shape throws here, read as none.
            $json = File::json($path, JSON_THROW_ON_ERROR);

            return [
                'snapshot' => [
                    'sapi' => Arr::string($json, 'sapi'),
                    'php' => Arr::string($json, 'php'),
                    'memory_limit' => Arr::string($json, 'memory_limit'),
                    'max_execution_time' => Arr::integer($json, 'max_execution_time'),
                    'disable_functions' => array_values(array_filter(Arr::array($json, 'disable_functions'), is_string(...))),
                    'extensions' => array_values(array_filter(Arr::array($json, 'extensions'), is_string(...))),
                    'image_formats' => array_filter(Arr::array($json, 'image_formats'), is_string(...)),
                ],
                'seen_at' => (int) filemtime($path),
            ];
        }, report: false);
    }

    private static function path(): string
    {
        return storage_path('framework/kami/web-environment.json');
    }
}
