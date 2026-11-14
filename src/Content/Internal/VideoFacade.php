<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use ErrorException;
use Illuminate\Support\Facades\File;
use Psyao\Kami\Content\Blocks\Video;

/**
 * The `Video` Block's facade script, Kami's only front JS (ADR 0009): prebuilt in the package, copied to `public/vendor/kami/`
 * by `kami:install`, beside the `.htaccess` that gives it `nosniff` on Apache (ADR 0018).
 *
 * @internal
 */
final class VideoFacade
{
    /** On the file Apache serves. No long cache: its name carries no version. */
    public const array HEADERS = ['X-Content-Type-Options' => 'nosniff'];

    /**
     * Copies the script and writes its `.htaccess`, both Kami's files, written whole at each run.
     *
     * @return string|null Why it could not, or null.
     */
    public static function install(): ?string
    {
        $script = public_path(Video::SCRIPT);
        $directory = dirname($script);

        if (file_exists($directory) && ! is_dir($directory)) {
            return 'public/' . dirname(Video::SCRIPT) . ' is a file, not a directory: move it out of public/.';
        }

        try {
            File::ensureDirectoryExists($directory);
            $written = File::copy(dirname(__DIR__, 3) . '/resources/dist/front/' . basename(Video::SCRIPT), $script)
                && File::put("{$directory}/.htaccess", sprintf(<<<'APACHE'
                    # Written by kami:install at each run: edits are lost.
                    <IfModule mod_headers.c>
                        Header always set X-Content-Type-Options "%s"
                    </IfModule>

                    APACHE, self::HEADERS['X-Content-Type-Options'])) !== false;
        } catch (ErrorException) {
            // Laravel turns PHP's warning into an exception: a directory or file the user running the command cannot write.
            $written = false;
        }

        return $written ? null : 'public/' . dirname(Video::SCRIPT) . ' cannot be written: let the user running kami:install write there.';
    }
}
