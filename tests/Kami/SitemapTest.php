<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Actions\TrashEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Facades\Kami;
use Workbench\App\Content\Article;
use Workbench\App\Content\Footer;
use Workbench\App\Content\Home;
use Workbench\App\Content\Label;
use Workbench\App\Content\Testimonial;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The test's own: parallel processes share the skeleton's public/ and storage/.
    $this->app->usePublicPath($this->public = temporaryPath());
    $this->app->useStoragePath($this->storage = temporaryPath());
    File::ensureDirectoryExists($this->public);
    Kami::routes();
});

afterEach(function () {
    File::deleteDirectory($this->public);
    File::deleteDirectory($this->storage);
});

/** @param array<string, mixed> $values */
function sitemapArticle(string $locale, array $values, ?Entry $entry = null): Entry
{
    return resolve(SaveEntry::class)->handle(Article::class, ['status' => 'published', ...($entry === null ? ['author' => 'Ada'] : []), ...$values], $entry, $locale);
}

/**
 * The URLs of a sitemap file, with their `lastmod` and alternates.
 *
 * @return array<string, array{lastmod: string, alternates: array<string, string>}>
 */
function sitemapUrls(string $file): array
{
    $xml = simplexml_load_string(File::get($file));
    $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
    $urls = [];

    foreach ($xml->xpath('//s:url') as $url) {
        $url->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $url->registerXPathNamespace('x', 'http://www.w3.org/1999/xhtml');
        $alternates = [];

        foreach ($url->xpath('x:link') as $link) {
            $alternates[(string) $link['hreflang']] = (string) $link['href'];
        }

        $urls[(string) $url->xpath('s:loc')[0]] = ['lastmod' => (string) $url->xpath('s:lastmod')[0], 'alternates' => $alternates];
    }

    return $urls;
}

/** @return list<string> The `loc` of each file the index lists. */
function sitemapIndex(string $file): array
{
    $xml = simplexml_load_string(File::get($file));
    $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');

    return array_map('strval', $xml->xpath('//s:sitemap/s:loc'));
}

it('writes an index over one file per Locale, each URL with its lastmod and alternates', function () {
    $this->travelTo('2026-11-08 10:00:00');
    $entry = sitemapArticle('fr', ['title' => 'Bonjour']);
    $this->travelTo('2026-11-08 11:00:00');
    sitemapArticle('en', ['title' => 'Hello'], $entry);

    $this->artisan('kami:sitemap')->assertSuccessful();

    expect(sitemapIndex("{$this->public}/sitemap.xml"))->toBe(['http://localhost/sitemaps/fr-1.xml', 'http://localhost/sitemaps/en-1.xml']);
    $alternates = ['fr' => 'http://localhost/articles/bonjour', 'en' => 'http://localhost/en/articles/hello', 'x-default' => 'http://localhost/articles/bonjour'];
    expect(sitemapUrls("{$this->public}/sitemaps/fr-1.xml"))->toBe(['http://localhost/articles/bonjour' => ['lastmod' => '2026-11-08T10:00:00+00:00', 'alternates' => $alternates]])
        ->and(sitemapUrls("{$this->public}/sitemaps/en-1.xml"))->toBe(['http://localhost/en/articles/hello' => ['lastmod' => '2026-11-08T11:00:00+00:00', 'alternates' => $alternates]]);
});

it('lists visible Translations not Hidden from search engines, the home page once at /', function () {
    $save = resolve(SaveEntry::class);
    $save->handle(Home::class, ['title' => 'Accueil', 'status' => 'published'], locale: 'fr');
    $both = sitemapArticle('fr', ['title' => 'Visible']);
    sitemapArticle('en', ['title' => 'Hidden', 'noindex' => true], $both);
    sitemapArticle('fr', ['title' => 'Draft', 'status' => 'draft']);
    sitemapArticle('fr', ['title' => 'Scheduled', 'published_at' => now()->addDay()]);
    resolve(TrashEntry::class)->handle(sitemapArticle('fr', ['title' => 'Trashed']));
    $save->handle(Label::class, ['title' => 'Partout', 'status' => 'published']);
    $save->handle(Testimonial::class, ['title' => 'No address', 'status' => 'published'], locale: 'fr');
    $save->handle(Footer::class, ['title' => 'Footer'], locale: 'fr');

    $this->artisan('kami:sitemap')->assertSuccessful();

    // A Hidden Translation leaves the language links in both directions; the Paths of a `'*'` Translation are each other's.
    $label = ['fr' => 'http://localhost/label/partout', 'en' => 'http://localhost/en/label/partout', 'x-default' => 'http://localhost/label/partout'];
    expect(array_map(fn (array $url) => $url['alternates'], sitemapUrls("{$this->public}/sitemaps/fr-1.xml")))->toBe([
        'http://localhost' => [],
        'http://localhost/articles/visible' => [],
        'http://localhost/label/partout' => $label,
    ])->and(array_map(fn (array $url) => $url['alternates'], sitemapUrls("{$this->public}/sitemaps/en-1.xml")))->toBe([
        'http://localhost/en/label/partout' => $label,
    ]);
});

it('rebuilds only after a core write or a passed visibility change', function () {
    // A build in the second of a write rebuilds once more, to be safe: the steps stay a minute apart.
    $this->travelTo('2026-11-08 10:00:00');
    sitemapArticle('fr', ['title' => 'Bonjour']);
    $this->travelTo('2026-11-08 10:01:00');
    $this->artisan('kami:sitemap')->expectsOutputToContain('Wrote the sitemap: 1 URLs.')->assertSuccessful();

    $this->travelTo('2026-11-08 11:00:00');
    $this->artisan('kami:sitemap')->expectsOutputToContain('The sitemap is up to date.')->assertSuccessful();

    sitemapArticle('fr', ['title' => 'Demain', 'published_at' => '2026-11-08 12:30:00']);
    $this->travelTo('2026-11-08 11:01:00');
    $this->artisan('kami:sitemap')->expectsOutputToContain('Wrote the sitemap: 1 URLs.')->assertSuccessful();
    $this->travelTo('2026-11-08 12:00:00');
    $this->artisan('kami:sitemap')->expectsOutputToContain('The sitemap is up to date.')->assertSuccessful();

    // Read from the page cache manifest, which no request has rewritten since.
    $this->travelTo('2026-11-08 13:00:00');
    $this->artisan('kami:sitemap')->expectsOutputToContain('Wrote the sitemap: 2 URLs.')->assertSuccessful();
    $this->travelTo('2026-11-08 13:01:00');
    $this->artisan('kami:sitemap')->expectsOutputToContain('The sitemap is up to date.')->assertSuccessful();
});

it('still sees a passed visibility change once the page cache manifest has moved on to the next one', function () {
    $this->travelTo('2026-11-08 10:00:00');
    sitemapArticle('fr', ['title' => 'Demain', 'published_at' => '2026-11-08 12:30:00']);
    sitemapArticle('fr', ['title' => 'Plus tard', 'published_at' => '2026-11-09 12:30:00']);
    $this->travelTo('2026-11-08 10:01:00');
    $this->artisan('kami:sitemap')->expectsOutputToContain('Wrote the sitemap: 0 URLs.')->assertSuccessful();

    $this->travelTo('2026-11-08 13:00:00');
    Kami::flushCache();

    $this->artisan('kami:sitemap')->expectsOutputToContain('Wrote the sitemap: 1 URLs.')->assertSuccessful();
});

it('swaps the new files in whole, a file no longer written gone', function () {
    $entry = sitemapArticle('fr', ['title' => 'Bonjour']);
    sitemapArticle('en', ['title' => 'Hello'], $entry);
    $this->artisan('kami:sitemap')->assertSuccessful();

    sitemapArticle('en', ['status' => 'draft'], $entry->refresh());
    $this->artisan('kami:sitemap')->assertSuccessful();

    expect(sitemapIndex("{$this->public}/sitemap.xml"))->toBe(['http://localhost/sitemaps/fr-1.xml'])
        ->and(File::allFiles($this->public))->toHaveCount(2)
        ->and(File::directories($this->public))->toBe(["{$this->public}/sitemaps"]);
});

it('writes no index over a route of the application, and lists its files for the application\'s own', function () {
    Route::get('sitemap.xml', fn () => 'the application\'s');
    sitemapArticle('fr', ['title' => 'Bonjour']);

    $this->artisan('kami:sitemap')->assertSuccessful();

    expect("{$this->public}/sitemap.xml")->not->toBeFile()
        ->and(Kami::sitemapUrls())->toBe(['http://localhost/sitemaps/fr-1.xml'])
        ->and($this->get('/sitemap.xml')->getContent())->toBe('the application\'s');
});

it('builds the files missing on a fresh install in the request, and serves them', function () {
    sitemapArticle('fr', ['title' => 'Bonjour']);

    $index = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml')->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    expect($index->getFile()->getPathname())->toBe("{$this->public}/sitemap.xml");
    File::deleteDirectory("{$this->public}/sitemaps");
    expect($this->get('/sitemaps/fr-1.xml')->assertOk()->streamedContent())->toContain('<loc>http://localhost/articles/bonjour</loc>');
});

it('answers a file a built sitemap does not have with a 404, building nothing', function () {
    $this->artisan('kami:sitemap')->assertSuccessful();
    File::delete("{$this->public}/sitemap.xml");

    $this->get('/sitemaps/fr-2.xml')->assertNotFound();
    $this->get('/sitemaps/../.env')->assertNotFound();

    expect("{$this->public}/sitemap.xml")->not->toBeFile();
});

it('keeps the sitemap routes out of the page cache and the session', function (string $uri) {
    expect(Route::getRoutes()->match(Request::create($uri))->gatherMiddleware())->not->toContain('kami', 'web');
})->with(['/sitemap.xml', '/sitemaps/fr-1.xml']);
