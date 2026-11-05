<?php

declare(strict_types=1);

namespace Psyao\Kami\Media\Internal;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * `public/media`, linked to `kami.media.public_disk`'s `media/`, from which Apache serves every Conversion written so
 * far without PHP; a missing file falls through `public/.htaccess`'s `!-f` to `RenderConversion` (ADR 0017).
 *
 * @internal
 */
final class PublicMedia
{
    /** The headers every file Apache serves from `public/media` carries, as `RenderConversion` sends them (ADRs 0017, 0018). */
    public const string NOSNIFF = 'nosniff';

    public const string CACHE_CONTROL = 'public, max-age=31536000, immutable';

    /** An SVG opened directly runs no script and loads nothing. */
    public const string SVG_CSP = "default-src 'none'; style-src 'unsafe-inline'; img-src data:";

    public static function link(): string
    {
        return public_path('media');
    }

    /** The disk's `media/` directory, which the link points to. */
    public static function target(): string
    {
        return Storage::disk(Config::string('kami.media.public_disk'))->path('media');
    }

    /** Why the link cannot exist, or null: Apache's `!-f` needs the files on this machine. */
    public static function nonLocalDisk(): ?string
    {
        $disk = Config::string('kami.media.public_disk');
        $driver = config("filesystems.disks.{$disk}.driver");

        return $driver === 'local' ? null : "kami.media.public_disk is {$disk}, whose driver is " . (is_string($driver) ? $driver : 'not declared') . ': Conversions need a local disk, which public/media links to.';
    }

    /**
     * Links `public/media` and writes its `.htaccess`, replacing Kami's own former link or file, never a directory of the site's.
     *
     * @return string|null Why it could not, or null.
     */
    public static function install(): ?string
    {
        if (($refusal = self::nonLocalDisk()) !== null) {
            return $refusal;
        }

        $link = self::link();
        $target = self::target();

        if (file_exists($link) && ! is_link($link)) {
            return 'public/media is a ' . (is_dir($link) ? 'directory' : 'file') . ', not a link: move it out of public/.';
        }

        File::ensureDirectoryExists($target);

        if (is_link($link) && readlink($link) !== $target) {
            File::delete($link);
        }

        if (! is_link($link)) {
            File::link($target, $link);
        }

        // In the disk's directory, through the link: Kami's file, written whole at each install.
        File::replace("{$target}/.htaccess", self::htaccess());

        return null;
    }

    /** No mod_rewrite directive: one here would replace `public/.htaccess`'s rules, and a missing Conversion would no longer reach Laravel. */
    private static function htaccess(): string
    {
        return sprintf(<<<'APACHE'
            # Written by kami:install at each run: edits are lost. The headers Laravel sends on a Conversion's first request.
            <IfModule mod_headers.c>
                Header always set X-Content-Type-Options "%s"
                Header set Cache-Control "%s"
                <FilesMatch "\.svg$">
                    Header always set Content-Security-Policy "%s"
                </FilesMatch>
            </IfModule>

            APACHE, self::NOSNIFF, self::CACHE_CONTROL, self::SVG_CSP);
    }
}
