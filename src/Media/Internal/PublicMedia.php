<?php

declare(strict_types=1);

namespace Psyao\Kami\Media\Internal;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * `public/media`, linked to `kami.media.public_disk`'s `media/`, from which Apache serves every Conversion, and every
 * document's or SVG's file, written so far without PHP; a missing file falls through `public/.htaccess`'s `!-f` to `RenderConversion` (ADR 0017).
 *
 * @internal
 */
final class PublicMedia
{
    /** On every file Apache serves from `public/media`, as `RenderConversion` and the front headers send them (ADRs 0017, 0018). */
    public const array HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Cache-Control' => 'public, max-age=31536000, immutable',
    ];

    /** On an SVG too: opened directly, it runs no script and loads nothing. */
    public const array SVG_HEADERS = [
        'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:",
    ];

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
    public static function localDiskRefusal(): ?string
    {
        $disk = Config::string('kami.media.public_disk');
        $driver = config("filesystems.disks.{$disk}.driver");

        return $driver === 'local' ? null : "kami.media.public_disk is {$disk}, whose driver is " . (is_string($driver) ? $driver : 'not declared') . ': Conversions need a local disk, which public/media links to.';
    }

    /**
     * Why the originals could be served, EXIF and GPS included, or null: a local `kami.media.originals_disk` whose `media/` lies
     * under `public/`, under the public disk's `media/` (which `public/media` links to) or under a `filesystems.links` target
     * (which `storage:link` exposes). Compared once resolved through symlinks, so a site root linking to `public/`, as one
     * Infomaniak layout does, counts. A remote disk's visibility is the site's to keep private.
     */
    public static function exposedOriginalsRefusal(): ?string
    {
        $disk = Config::string('kami.media.originals_disk');
        $root = config("filesystems.disks.{$disk}.driver") === 'local' ? config("filesystems.disks.{$disk}.root") : null;

        if (! is_string($root)) {
            return null;
        }

        $publicRoot = config('filesystems.disks.' . Config::string('kami.media.public_disk') . '.root');
        /** @var array<string, string> $links */
        $links = config('filesystems.links', []);
        $originals = self::resolved("{$root}/media") . DIRECTORY_SEPARATOR;

        foreach ([public_path(), ...(is_string($publicRoot) ? ["{$publicRoot}/media"] : []), ...array_values($links)] as $served) {
            // Both ending with a separator: equal paths match, and `public-old/` does not count as under `public/`.
            if (str_starts_with($originals, self::resolved($served) . DIRECTORY_SEPARATOR)) {
                return "kami.media.originals_disk is {$disk}, whose media/ (" . rtrim($originals, DIRECTORY_SEPARATOR) . ') can be served from public/: originals keep their metadata, GPS included.';
            }
        }

        return null;
    }

    /** `realpath()` of the nearest existing parent, with the missing rest appended: a disk's `media/` may not exist yet. */
    private static function resolved(string $path): string
    {
        $missing = '';

        while (($real = realpath($path)) === false && dirname($path) !== $path) {
            $missing = DIRECTORY_SEPARATOR . basename($path) . $missing;
            $path = dirname($path);
        }

        return ($real === false ? $path : $real) . $missing;
    }

    /** Resolved, so a relative link to the same directory counts. */
    public static function isLinked(): bool
    {
        $target = realpath(self::target());

        return is_link(self::link()) && $target !== false && realpath(self::link()) === $target;
    }

    /**
     * Links `public/media` and writes its `.htaccess`, replacing Kami's own former link or file, never a directory of the site's.
     *
     * @return string|null Why it could not, or null.
     */
    public static function install(): ?string
    {
        if (($refusal = self::localDiskRefusal()) !== null) {
            return $refusal;
        }

        $link = self::link();
        $target = self::target();

        if (file_exists($link) && ! is_link($link)) {
            return 'public/media is a ' . (is_dir($link) ? 'directory' : 'file') . ', not a link: move it out of public/.';
        }

        File::ensureDirectoryExists($target);

        // Any other path is a link by now, or nothing: delete is silent on a missing one.
        if (! self::isLinked()) {
            File::delete($link);
            File::link($target, $link);
        }

        // In the disk's directory, through the link: Kami's file, written whole at each install.
        File::replace("{$target}/.htaccess", self::htaccess());

        return null;
    }

    /**
     * No mod_rewrite directive: one here would replace `public/.htaccess`'s rules, and a missing Conversion would no longer reach Laravel.
     * `X-Robots-Tag` follows `kami.indexable` as at the install, as the front's does on the Conversion's first request (ADR 0027).
     */
    private static function htaccess(): string
    {
        return sprintf(<<<'APACHE'
            # Written by kami:install at each run: edits are lost.
            <IfModule mod_headers.c>
                Header always set X-Content-Type-Options "%s"
                Header set Cache-Control "%s"%s
                <FilesMatch "\.svg$">
                    Header always set Content-Security-Policy "%s"
                </FilesMatch>
            </IfModule>

            APACHE, self::HEADERS['X-Content-Type-Options'], self::HEADERS['Cache-Control'], config('kami.indexable') ? '' : "\n    Header always set X-Robots-Tag \"noindex, nofollow\"", self::SVG_HEADERS['Content-Security-Policy']);
    }
}
