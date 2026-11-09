<?php

declare(strict_types=1);

namespace Psyao\Kami;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Psyao\Kami\Content\Internal\PreviewLink;
use Psyao\Kami\Media\Internal\PublicMedia;
use Psyao\Kami\Media\Internal\RenderConversion;
use Psyao\Kami\Routing\Internal\PageCache;
use Psyao\Kami\Routing\Internal\RenderEntry;
use Psyao\Kami\Routing\Internal\RenderPreviewLink;
use Psyao\Kami\Seo\Internal\ResolvePageMeta;
use Psyao\Kami\Seo\Internal\ServeSitemap;
use Psyao\Kami\Seo\Internal\Sitemap;
use Psyao\Kami\Seo\PageMeta;
use Psyao\Kami\Support\Internal\FrontSecurityHeaders;

/** What a site calls on Kami through the `Kami` facade. */
final class Kami
{
    /**
     * Registers Kami's front: a fallback route, `/kami/share/{token}` for Preview links, `/media/{ulid}/{v}/{file}` for Conversions and the files of documents and SVG, and `/sitemap.xml` and `/sitemaps/{file}`, which build the sitemap when its static files are missing.
     *
     * The fallback lets the site's own routes always win, in the `kami` middleware group. All run without the `web` group's session, cookies and CSRF, even when called inside it,
     * but the fallback under `$session`: it then joins the `web` group, so the application's signed-in members stay signed in on Kami's pages, which the page cache never stores (ADR 0038).
     */
    public function routes(bool $session = false): void
    {
        // Sets its Locale itself, as its URL names none, and sends no referrer: the token must not leave through an outbound link.
        Route::get('kami/share/{token}', RenderPreviewLink::class)
            ->middleware(FrontSecurityHeaders::class . ':Referrer-Policy=no-referrer')
            ->withoutMiddleware('web');
        // An SVG's own file first, under a CSP of its own, enforced even when the front's is report-only: the front's middleware replaces any the response sets.
        Route::get('media/{media}/{version}/{svg}', RenderConversion::class)
            ->where('svg', '[a-z0-9-]+\.svg')
            ->middleware(FrontSecurityHeaders::class . ':Content-Security-Policy=' . PublicMedia::SVG_HEADERS['Content-Security-Policy'])
            ->withoutMiddleware('web');
        Route::get('media/{media}/{version}/{file}', RenderConversion::class)->middleware(FrontSecurityHeaders::class)->withoutMiddleware('web');
        // A route of the application registered earlier keeps `/sitemap.xml`: Laravel would let the last one registered win (ADR 0038).
        if (! isset(Route::getRoutes()->get('GET')['sitemap.xml'])) {
            Route::get('sitemap.xml', ServeSitemap::class)->middleware(FrontSecurityHeaders::class)->withoutMiddleware('web');
        }

        Route::get('sitemaps/{file}', ServeSitemap::class)->where('file', '[A-Za-z0-9_-]+-[1-9][0-9]*\.xml')->middleware(FrontSecurityHeaders::class)->withoutMiddleware('web');
        $fallback = Route::fallback(RenderEntry::class);
        if ($session) {
            $fallback->middleware(['web', 'kami']);
        } else {
            $fallback->middleware('kami')->withoutMiddleware('web');
        }
    }

    /** The current page's head values: a Kami page's own, or the Site's on a site route or an error page. */
    public function seo(): PageMeta
    {
        $meta = request()->attributes->get(ResolvePageMeta::ATTRIBUTE);

        return $meta instanceof PageMeta ? $meta : ResolvePageMeta::forSite();
    }

    /**
     * Caches a fragment on Laravel's default store until any core write, `kami:install` or `Kami::flushCache()`, or its TTL: the key carries the Content version, so it needs no tags (ADR 0021).
     * Runs the callback uncached while `storage/framework/kami/pages/` cannot be written, which `kami:doctor` reports.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    public function remember(string $key, DateTimeInterface | DateInterval | int $ttl, Closure $callback): mixed
    {
        $version = PageCache::version();

        return $version === null ? $callback() : Cache::remember("kami:{$version}:{$key}", $ttl, $callback);
    }

    /** Keeps the current response out of the page cache, as for a page showing the time of day. */
    public function withoutPageCache(): void
    {
        request()->attributes->set(PageCache::SKIP, true);
    }

    /**
     * The absolute URLs of Kami's per-Locale sitemap files, built first when missing, for an application that answers `/sitemap.xml` itself to list in its own index.
     *
     * @return list<string>
     */
    public function sitemapUrls(): array
    {
        return Sitemap::urls();
    }

    /** Makes every cached page and fragment stale, after a change Kami cannot see, such as the host application's data. */
    public function flushCache(): void
    {
        PageCache::invalidate();
    }

    /**
     * Declares Kami's Scheduled tasks on Laravel's `Schedule`, for `routes/console.php`. A Tick runs them in-process, or `schedule:run` from a server's cron (ADR 0020).
     *
     * Each is short and runs once at a time; a mutex held after ten minutes belongs to a run that died, so it no longer stops the next one.
     */
    public function schedule(): void
    {
        $schedule = resolve(Schedule::class);
        $schedule->command('kami:sitemap')->hourly()->withoutOverlapping(10);
        $schedule->command('kami:audit:prune')->daily()->withoutOverlapping(10);
        $schedule->command('model:prune', ['--model' => PreviewLink::class])->daily()->withoutOverlapping(10);
        // The backstop for a `defer()` that never ran after a new version (ADR 0021).
        $schedule->call(PageCache::prune(...))->name('kami:page-cache:prune')->daily()->withoutOverlapping(10);
        $schedule->command('kami:media:prune')->weekly()->withoutOverlapping(10);
    }
}
