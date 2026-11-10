# Page cache

A page Kami has rendered once is stored and served again, with no query and no rendering, until anything visitors might see changes. Fragments of your own pages go stale at the same moment through `Kami::remember()`. Nothing needs to run for it: no cron, no queue, no tags in the cache store (ADR 0021).

## What is cached

Kami stores a response of its Entry route only when all of these hold:

- the request is `GET` or `HEAD`, with no query string and no `Authorization` header;
- the request carries no session, as under [`Kami::routes(session: true)`](existing-application.md#keep-your-members-signed-in);
- the request's host is the Site's, as `url` sets it in `config/kami.php`, so a forged `Host` header cannot fill the disk;
- the response is a 200 and sets no cookie;
- the response did not call [`Kami::withoutPageCache()`](#keeping-a-page-out).

Redirects, 404 and 410 pages, Previews, Preview links, Conversions and your own routes are never stored. A stored page is replayed with its headers, the Content Security Policy included, but `Set-Cookie` and `Date`. It carries an `ETag` and `Cache-Control: no-cache`: browsers ask again each time, and a page that has not changed answers `304 Not Modified` with no body.

## Serving before Laravel boots

The starter kit's `public/index.php` serves a cached page before Laravel boots, with no autoload, no framework and no query. One line does it, right after Laravel's maintenance check:

```php
// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__ . '/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Serve a page from Kami's page cache...
(require __DIR__ . '/../vendor/psyao/kami/resources/page-cache.php')(__DIR__ . '/../storage');
```

The line reads the manifest and one file, sends the stored page and stops. Anything else, a stale page, a page never stored or an unknown manifest, lets the request through to Laravel. So does a site where no [Tick](scheduler.md) ran for five minutes, from any trigger: Laravel then serves the stored page itself, and its response starts a Tick, which pages sent before Laravel boots never could. The check reads the time of three files, `storage/framework/kami/tick-*`. Keep it after the maintenance check. While `php artisan down` is on, the line sends no cached page and Laravel answers 503: it looks for the `storage/framework/down` file that Laravel's default `file` maintenance driver writes. With `MAINTENANCE_DRIVER=cache` there is no such file: run `php artisan kami:cache:clear` right after `php artisan down`, and nothing is stored again until the site is up. A `storage/` moved elsewhere is the path the line passes.

Without the line the page cache still works: Kami's middleware serves the same pages once Laravel has booted, with no query and no rendering. An [existing application](existing-application.md#serve-cached-pages-before-laravel-boots) adds the line by hand.

## When pages go stale

One Content version covers the whole installation, and every cached page and fragment is keyed by it. A new version makes all of them stale at once:

- any write through a core action: `SaveEntry` (Global Sets included), `DuplicateEntry`, `TrashEntry`, `RestoreEntry`, `DeleteEntry`, `DeleteTranslation`, `SaveMedia`, `DeleteMedia`, and reordering a type in the admin;
- every `kami:install`, as a deploy may have changed views;
- `Kami::flushCache()`, `kami:cache:clear` and `optimize:clear`;
- the moment a Scheduled Translation appears: the first request after it recomputes the next such moment and makes a new version, so a scheduled article appears on time on every page that lists it.

Each page also expires one hour after it was stored, as a net for what Kami cannot see, such as a page showing the date. Invalidation is deliberately coarse: a write on a small site is rare next to its reads, and a type's `render()` may show anything.

Data Kami does not own, your application's tables for instance, changes no version. After writing it, call `Kami::flushCache()`:

```php
use Psyao\Kami\Facades\Kami;

$event->save();

Kami::flushCache();
```

## Keeping a page out

A page that must stay live, because it shows the time of day or something per visitor, calls `Kami::withoutPageCache()` while it renders, from its type's `render()` or its view:

```php
public function render(Entry $entry): View
{
    Kami::withoutPageCache();

    return view('kami.opening-hours', ['entry' => $entry, 'open' => now()->isWeekday()]);
}
```

A response that sets a cookie is never stored either, so a page in a host's session stays out with no call.

## Caching a fragment

Kami caches nothing of its own while it renders: a page stays within its query budget uncached. For an expensive part of your own, `Kami::remember($key, $ttl, $callback)` caches it on Laravel's default cache store until the next Content version or its TTL:

```php
$articles = Kami::remember('home-articles', now()->addDay(), fn () => Entry::query()->ofType(Article::class)->limit(5)->get());
```

A key of a former version is never read again and expires with its TTL. On shared hosting, set `CACHE_STORE=file`, as the starter kit does: the `database` store pays a round trip to the database on every read.

## Turning it off

The page cache is on unless `APP_DEBUG` is, so a page you debug is never a cached one. Set it in `config/kami.php`:

```php
'page_cache' => [
    'enabled' => false,
],
```

With it off, `Kami::remember()` still caches fragments. The line in `public/index.php` cannot read the configuration: after turning the page cache off by hand, run `php artisan kami:cache:clear` so it finds no page left. A deploy needs nothing, as `kami:install` makes a new Content version.

## Storage

Pages live under `storage/framework/kami/pages/`, one directory per Content version, and the files of former versions are deleted after the response that replaced them. `kami:doctor` warns when that directory cannot be written, whether the page cache is on or off: every page then boots Laravel, and `Kami::remember()` runs each fragment's callback with nothing cached, as its keys carry the version the manifest holds. A manifest left behind in a directory that became read-only is worse: no write can replace its version, so pages and fragments stay as they were until they expire. Fix the permissions, then run `php artisan kami:cache:clear`.

It works the same on shared hosting and on a server: a hit reads the manifest and one file, with no query, and with the line above it compiles about a hundred lines of PHP rather than Laravel, which matters where OPcache is off. The files sit on the web server's own disk, so an installation behind several web servers either shares `storage/` between them or turns the page cache off. The line reads the host from the request's `Host` header: behind a proxy that rewrites it, it finds no page and every request boots Laravel, which still serves the cached page.

## Measuring

`php artisan kami:bench` prints the median server time of a page served from the page cache and rendered by Laravel, measured from the host ([Deployment](deployment.md#measuring-page-speed)).

## Clearing

`php artisan kami:cache:clear` makes every page and fragment stale and deletes every stored page. `php artisan optimize:clear` runs it too.
