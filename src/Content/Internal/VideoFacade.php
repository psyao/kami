<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Illuminate\Support\Facades\File;

/**
 * The `Video` Block's facade script, Kami's only front JS (ADR 0009): prebuilt in the package, copied to `public/vendor/kami/`
 * by `kami:install`, beside the `.htaccess` that gives it `nosniff` on Apache (ADR 0018).
 *
 * @internal
 */
final class VideoFacade
{
    /** Its path under `public/`, as the view asks for it. */
    public const string PATH = 'vendor/kami/video.js';

    /** On the file Apache serves. No long cache: its name carries no version. */
    public const array HEADERS = ['X-Content-Type-Options' => 'nosniff'];

    /** Copies the script and writes its `.htaccess`, both Kami's files, written whole at each run. */
    public static function install(): void
    {
        $directory = dirname(public_path(self::PATH));
        File::ensureDirectoryExists($directory);
        File::copy(dirname(__DIR__, 3) . '/resources/dist/front/' . basename(self::PATH), public_path(self::PATH));
        File::replace("{$directory}/.htaccess", sprintf(<<<'APACHE'
            # Written by kami:install at each run: edits are lost.
            <IfModule mod_headers.c>
                Header always set X-Content-Type-Options "%s"
            </IfModule>

            APACHE, self::HEADERS['X-Content-Type-Options']));
    }
}
