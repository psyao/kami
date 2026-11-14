<?php

declare(strict_types=1);

namespace Psyao\Kami;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Content\Internal\PreviewLink;
use Psyao\Kami\Forms\Form;
use Psyao\Kami\Forms\Internal\Forms;
use Psyao\Kami\Forms\Internal\SubmitForm;
use Psyao\Kami\Media\Internal\PublicMedia;
use Psyao\Kami\Media\Internal\RenderConversion;
use Psyao\Kami\Routing\Internal\PageCache;
use Psyao\Kami\Routing\Internal\RenderEntry;
use Psyao\Kami\Routing\Internal\RenderFeed;
use Psyao\Kami\Routing\Internal\RenderPreviewLink;
use Psyao\Kami\Scheduling\Internal\RunTick;
use Psyao\Kami\Seo\Internal\ResolvePageMeta;
use Psyao\Kami\Seo\Internal\ServeSitemap;
use Psyao\Kami\Seo\Internal\Sitemap;
use Psyao\Kami\Seo\PageMeta;
use Psyao\Kami\Sites\Internal\Site;
use Psyao\Kami\Support\Internal\FrontSecurityHeaders;

/** What a site calls on Kami through the `Kami` facade. */
final class Kami
{
    /**
     * Registers Kami's front: a fallback route for Entries and Archives, `/kami/share/{token}` for Preview links, `/media/{ulid}/{v}/{file}` for Conversions and the files of documents and SVG, `/sitemap.xml` and `/sitemaps/{file}`, which build the sitemap when its static files are missing, `/feed` and `/{prefix}/feed` for Feeds under every Locale segment, `/kami/tick/{token}` for a host's planner, and `POST /kami/forms/{handle}` for Forms. `$middleware` adds the site's rendering middleware, such as Inertia's, to the pages alone: Entries, Archives and Term Archives.
     *
     * The fallback lets the site's own routes always win, in the `kami` middleware group. All run without the `web` group's session, cookies and CSRF, even when called inside it,
     * but the fallback and the form route under `$session`: they then join the `web` group, its CSRF check included, so the application's signed-in members stay signed in on Kami's pages, which the page cache never stores (ADR 0038).
     *
     * `$middleware` runs on the fallback only, after the `kami` group: never on the Tick, media, Preview links, the sitemap, Feeds or the form route (ADRs 0038, 0040).
     * Feeds answer at the addresses the declarations give when this runs: `php artisan optimize` caches them with the routes.
     *
     * @param  list<string>  $middleware
     */
    public function routes(bool $session = false, array $middleware = []): void
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

        // Its addresses alone, so a Path ending in `feed` elsewhere stays the fallback's; declarations read inside `rescue()`, as this runs at every boot,
        // and an invalid one must not break Artisan: the requests reading types report it.
        $feed = rescue(fn (): ?string => $this->feedPattern(), null, false);

        if ($feed !== null) {
            Route::get('{feed}', RenderFeed::class)->where('feed', $feed)->middleware('kami')->withoutMiddleware('web');
        }

        // The host's planner calls it with no session or cookie (ADR 0020).
        Route::match(['GET', 'POST'], 'kami/tick/{token}', RunTick::class)->name('kami.tick')->middleware(FrontSecurityHeaders::class)->withoutMiddleware('web');
        Route::get('sitemaps/{file}', ServeSitemap::class)->where('file', '[A-Za-z0-9_-]+-[1-9][0-9]*\.xml')->middleware(FrontSecurityHeaders::class)->withoutMiddleware('web');
        $form = Route::post('kami/forms/{handle}', SubmitForm::class)->name('kami.form');
        $fallback = Route::fallback(RenderEntry::class);

        foreach ([$form, $fallback] as $route) {
            if ($session) {
                $route->middleware(['web', 'kami']);
            } else {
                $route->middleware('kami')->withoutMiddleware('web');
            }
        }

        $fallback->middleware($middleware);
    }

    /** The Feed addresses, under every Locale segment, as a route pattern; null with no Feed. */
    private function feedPattern(): ?string
    {
        $feeds = array_keys(resolve(ContentTypes::class)->feeds());
        $locales = array_map(fn (string $locale): string => preg_quote($locale) . '/', array_slice(Site::current()->locales, 1));

        return $feeds === [] ? null : ($locales === [] ? '' : '(?:' . implode('|', $locales) . ')?') . '(?:' . implode('|', array_map(preg_quote(...), $feeds)) . ')';
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

    /** A Form listed under `kami.forms`, by handle, for its view: `Kami::form('contact')->sent()`. */
    public function form(string $handle): Form
    {
        return resolve(Forms::class)->find($handle) ?? throw new InvalidArgumentException("No Form [{$handle}] is listed under kami.forms.");
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
        // The side effects of a Scheduled publication: visibility never waits for it (ADR 0019).
        $schedule->command('kami:announce-publications')->everyMinute()->withoutOverlapping(10);
        // The backstop for a Submission's delivery that failed, or whose `defer()` never ran.
        $schedule->command('kami:forms:deliver')->everyFifteenMinutes()->withoutOverlapping(10);
        $schedule->command('kami:sitemap')->hourly()->withoutOverlapping(10);
        $schedule->command('kami:forms:prune')->daily()->withoutOverlapping(10);
        $schedule->command('kami:audit:prune')->daily()->withoutOverlapping(10);
        $schedule->command('model:prune', ['--model' => PreviewLink::class])->daily()->withoutOverlapping(10);
        // The backstop for a `defer()` that never ran after a new version (ADR 0021).
        $schedule->call(PageCache::prune(...))->name('kami:page-cache:prune')->daily()->withoutOverlapping(10);
        $schedule->command('kami:media:prune')->weekly()->withoutOverlapping(10);
    }
}
