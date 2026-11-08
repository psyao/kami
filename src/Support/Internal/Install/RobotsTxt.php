<?php

declare(strict_types=1);

namespace Psyao\Kami\Support\Internal\Install;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Psyao\Kami\Sites\Internal\Site;

/**
 * The `public/robots.txt` `kami:install` writes and keeps, a static file since Infomaniak answers a missing one itself (ADR 0027).
 *
 * ponytail: the `default` Site only, as `Site::current()`; V1 multisite needs a per-host answer (ADR 0027, Multi-site door).
 *
 * @internal
 */
final class RobotsTxt
{
    /** Without it as the first line, the file is the site's own and Kami never touches it again. */
    public const string MARKER = '# Managed by Kami (kami:install). Delete this line to own this file.';

    /** What `kami:install` writes: the Kami panels disallowed and the sitemap named, or everything disallowed when the Site is not indexable. */
    public static function expected(): string
    {
        if (! config('kami.indexable')) {
            return self::MARKER . "\nUser-agent: *\nDisallow: /\n";
        }

        $host = parse_url(Site::current()->url, PHP_URL_HOST);
        $disallowed = [];

        // A panel at the root would disallow the front; one on a domain of its own has a robots.txt of its own.
        foreach (resolve(PluginRegistration::class)->panels as $panel) {
            ['path' => $path, 'domains' => $domains] = $panel();
            $path = trim($path, '/');

            if ($path !== '' && ($domains === [] || in_array($host, $domains, true))) {
                $disallowed[] = "Disallow: /{$path}\n";
            }
        }

        return self::MARKER . "\nUser-agent: *\n" . implode('', array_unique($disallowed)) . "\nSitemap: " . self::sitemapUrl() . "\n";
    }

    /** The sitemap index's absolute URL, which ADR 0027 asks `robots.txt` to name. */
    public static function sitemapUrl(): string
    {
        return mb_rtrim(Site::current()->url, '/') . '/sitemap.xml';
    }

    /** Whether a route of the host, not Kami's fallback, answers this address on the Site: Apache would serve a file in its place, so Kami writes none there (ADR 0038). */
    public static function hostRouteAnswers(string $path): bool
    {
        $request = Request::create(mb_rtrim(Site::current()->url, '/') . $path);

        return rescue(fn (): bool => ! Route::getRoutes()->match($request)->isFallback, false, report: false);
    }

    /**
     * Writes the file when it is missing or managed and out of date.
     *
     * @return string|null What `kami:install` reports: why the file was left alone, that it was written, or `null` when it was already up to date.
     */
    public static function write(): ?string
    {
        if (self::hostRouteAnswers('/robots.txt')) {
            return 'public/robots.txt was not written: a route of the application answers /robots.txt.';
        }

        $file = public_path('robots.txt');
        $current = is_file($file) ? File::get($file) : null;

        if ($current !== null && ! str_starts_with($current, self::MARKER)) {
            return "public/robots.txt is the site's own, without Kami's first line: left as it is.";
        }

        if ($current === ($expected = self::expected())) {
            return null;
        }

        // `filament:assets` has just written to public/, so it is writable.
        File::put($file, $expected);

        return 'Wrote public/robots.txt.';
    }
}
