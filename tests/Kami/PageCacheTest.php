<?php

declare(strict_types=1);

use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Psyao\Kami\Content\Actions\DuplicateEntry;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Actions\TrashEntry;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Internal\ReorderEntries;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Media\Actions\SaveMedia;
use Psyao\Kami\Routing\Internal\PageCache;
use Symfony\Component\HttpFoundation\Response;
use Workbench\App\Content\Article;
use Workbench\App\Content\Project;

uses(RefreshDatabase::class);

/** A type with no Field, so no table of its own, whose `render()` each test sets. */
final class Notice extends ContentType
{
    /** @var Closure(Entry): Response */
    public static Closure $render;

    public function render(Entry $entry): Response
    {
        return (self::$render)($entry);
    }
}

beforeEach(function () {
    // A `storage/` of the test's own: parallel processes share the skeleton's, and the suite turns the page cache off for the others.
    $this->app->useStoragePath($this->storage = temporaryPath());
    config(['kami.page_cache.enabled' => true]);
    Kami::routes();
});

afterEach(function () {
    File::deleteDirectory($this->storage);
});

/** @param array<string, mixed> $values */
function cachedArticle(array $values = []): Entry
{
    return resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Hello world', 'status' => 'published', 'author' => 'Ada Lovelace', ...$values]);
}

/** A change no core action sees: the page cache cannot know of it. */
function retitleBehindKamisBack(string $title): void
{
    DB::table('kami_translations')->update(['title' => $title]);
}

it('serves a page rendered once until a core write', function () {
    $entry = cachedArticle();
    $this->get('/articles/hello-world')->assertOk()->assertSee('Hello world');

    retitleBehindKamisBack('Unseen');
    $this->get('/articles/hello-world')->assertOk()->assertSee('Hello world')->assertDontSee('Unseen');

    resolve(SaveEntry::class)->handle(Article::class, ['summary' => 'Edited.'], $entry);
    $this->get('/articles/hello-world')->assertOk()->assertSee('Unseen');
});

it('replays the stored headers with an ETag, no-cache and a 304 on a match', function () {
    cachedArticle();
    $first = $this->get('/articles/hello-world');
    $etag = $first->headers->get('ETag');

    $hit = $this->get('/articles/hello-world')->assertOk()->assertHeader('ETag', $etag);

    expect($etag)->toMatch('~^"[0-9a-f]{16}-[0-9a-f]+"$~')
        ->and($hit->headers->get('Cache-Control'))->toContain('no-cache')->toContain('private')
        ->and($hit->headers->get('Content-Security-Policy'))->toBe($first->headers->get('Content-Security-Policy'))
        ->and($hit->headers->get('Content-Type'))->toBe($first->headers->get('Content-Type'));

    $this->get('/articles/hello-world', ['If-None-Match' => $etag])->assertStatus(304)->assertContent('');
});

it('stores a page once per Content version on disk, its old versions deleted after the response', function () {
    $entry = cachedArticle();
    $this->get('/articles/hello-world');
    resolve(SaveEntry::class)->handle(Article::class, ['summary' => 'Edited.'], $entry);
    $this->get('/articles/hello-world');

    expect(File::directories(storage_path('framework/kami/pages')))->toHaveCount(1)
        ->and(File::allFiles(storage_path('framework/kami/pages')))->toHaveCount(2);
});

it('never stores what is not a 200 of an Entry, without query string or credentials, on the Site\'s host', function (Closure $request) {
    cachedArticle();
    cachedArticle(['title' => 'Old', 'status' => 'draft']);
    $request($this);

    retitleBehindKamisBack('Unseen');
    DB::table('kami_translations')->update(['status' => 'published', 'published_at' => now()]);

    $request($this)->assertOk()->assertSee('Unseen');
})->with([
    'a query string' => [fn ($test) => $test->get('/articles/hello-world?utm=x')],
    'an Authorization header' => [fn ($test) => $test->get('/articles/hello-world', ['Authorization' => 'Bearer x'])],
    'another host' => [fn ($test) => $test->get('http://forged.test/articles/hello-world')],
    'a 404' => [fn ($test) => $test->get('/articles/old')],
]);

it('serves a cached page on a Site URL whose host has capitals', function () {
    config(['kami.sites.default.url' => 'http://Kami.Test']);
    cachedArticle();
    $this->get('http://kami.test/articles/hello-world')->assertOk();

    retitleBehindKamisBack('Unseen');

    $this->get('http://kami.test/articles/hello-world')->assertOk()->assertSee('Hello world')->assertDontSee('Unseen');
});

it('never stores a response that sets a cookie or calls withoutPageCache()', function (Closure $render) {
    config(['kami.types' => [Article::class, Notice::class]]);
    Notice::$render = $render;
    resolve(SaveEntry::class)->handle(Notice::class, ['title' => 'Hello world', 'status' => 'published']);
    $this->get('/notice/hello-world')->assertOk();

    retitleBehindKamisBack('Unseen');

    $this->get('/notice/hello-world')->assertSee('Unseen');
})->with([
    'a cookie' => [fn (Entry $entry) => response((string) $entry->title)->cookie('visit', '1')],
    'withoutPageCache()' => [function (Entry $entry) {
        Kami::withoutPageCache();

        return response((string) $entry->title);
    }],
]);

it('keeps the host\'s session on Kami::routes(session: true), its pages neither stored nor served from the page cache', function (bool $inWebGroup) {
    cachedArticle();
    $this->get('/articles/hello-world');
    $inWebGroup ? Route::middleware('web')->group(fn () => Kami::routes(session: true)) : Kami::routes(session: true);
    retitleBehindKamisBack('Unseen');

    $this->get('/articles/hello-world')->assertOk()->assertSee('Unseen')->assertCookie(config('session.cookie'));

    retitleBehindKamisBack('Unstored');

    $this->get('/articles/hello-world')->assertSee('Unstored');
    $this->get('/articles/nowhere')->assertNotFound()->assertCookie(config('session.cookie'));
})->with([
    'in routes/web.php' => [true],
    'outside the web group' => [false],
]);

it('keeps media, Preview links and the sitemap out of the session on Kami::routes(session: true)', function (string $uri) {
    Kami::routes(session: true);

    expect(Route::getRoutes()->match(Request::create($uri))->gatherMiddleware())->not->toContain('web');
})->with(['/kami/share/token', '/media/1/abc/photo.jpg', '/media/1/abc/logo.svg', '/sitemap.xml', '/sitemaps/fr-1.xml']);

it('stores nothing with the page cache off', function () {
    config(['kami.page_cache.enabled' => false]);
    cachedArticle();
    $this->get('/articles/hello-world');

    retitleBehindKamisBack('Unseen');

    $this->get('/articles/hello-world')->assertSee('Unseen');
    expect(File::isDirectory(storage_path('framework/kami/pages/' . PageCache::version())))->toBeFalse();
});

it('makes a new version on every kind of core write', function (Closure $write) {
    Storage::fake('local');
    cachedArticle();
    $project = resolve(SaveEntry::class)->handle(Project::class, ['title' => 'One'], locale: 'fr');
    resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Two'], locale: 'fr');
    $this->get('/articles/hello-world');
    retitleBehindKamisBack('Unseen');

    $write($project);

    $this->get('/articles/hello-world')->assertSee('Unseen');
})->with([
    'a trash' => [fn (Entry $project) => resolve(TrashEntry::class)->handle($project)],
    'a duplicate' => [fn (Entry $project) => resolve(DuplicateEntry::class)->handle($project)],
    'a reorder' => [fn () => resolve(ReorderEntries::class)->handle(new Project, Entry::query()->withUnpublished()->ofType(Project::class)->pluck('id')->reverse()->values()->all())],
    'a Media upload' => [fn () => resolve(SaveMedia::class)->handle(UploadedFile::fake()->image('photo.jpg', 10, 10), ['alt' => 'A photo'])],
]);

it('expires a cached page at the next visibility change, then records the one after', function () {
    $this->freezeSecond();
    cachedArticle();
    cachedArticle(['title' => 'Later', 'published_at' => now()->addMinutes(45)]);
    cachedArticle(['title' => 'Sooner', 'published_at' => now()->addMinutes(30)]);
    cachedArticle(['title' => 'Draft', 'status' => 'draft', 'published_at' => now()->addMinutes(10)]);
    $this->get('/articles/hello-world');
    retitleBehindKamisBack('Unseen');

    $this->travel(29)->minutes();
    $this->get('/articles/hello-world')->assertDontSee('Unseen');

    $this->travel(1)->minutes();
    $this->get('/articles/hello-world')->assertSee('Unseen');
    expect(PageCache::manifest()['next_change_at'] ?? null)->toBe(now()->addMinutes(15)->getTimestamp());
});

it('expires a cached page one hour after it was written', function () {
    cachedArticle();
    $this->get('/articles/hello-world');
    retitleBehindKamisBack('Unseen');

    $this->travel(59)->minutes();
    $this->get('/articles/hello-world')->assertDontSee('Unseen');

    $this->travel(2)->minutes();
    $this->get('/articles/hello-world')->assertSee('Unseen');
});

it('reads a missing, unreadable or malformed manifest as unknown, never as nothing Scheduled (#268)', function (?string $manifest) {
    $this->freezeSecond();
    cachedArticle();
    cachedArticle(['title' => 'Sooner', 'published_at' => now()->addDay()]);
    $this->get('/articles/hello-world');
    $path = storage_path('framework/kami/pages/manifest.json');
    $manifest === null ? File::delete($path) : File::put($path, $manifest);
    retitleBehindKamisBack('Unseen');

    $this->get('/articles/hello-world')->assertSee('Unseen');
    expect(PageCache::manifest()['next_change_at'] ?? null)->toBe(now()->addDay()->getTimestamp());
})->with([
    'missing' => [null],
    'not JSON' => ['{"version":'],
    'no next change' => ['{"version":"0123456789abcdef"}'],
    'a date string' => ['{"version":"0123456789abcdef","next_change_at":"2099-01-01"}'],
]);

it('keeps a fragment of Kami::remember() until a core write', function () {
    $entry = cachedArticle();
    $calls = 0;
    $fragment = function () use (&$calls) {
        return ++$calls;
    };

    expect(Kami::remember('listing', 60, $fragment))->toBe(1)
        ->and(Kami::remember('listing', 60, $fragment))->toBe(1);

    resolve(SaveEntry::class)->handle(Article::class, ['summary' => 'Edited.'], $entry);

    expect(Kami::remember('listing', 60, $fragment))->toBe(2);
});

it('makes every page and fragment stale and deletes every stored page on kami:cache:clear and optimize:clear', function (string $command) {
    cachedArticle();
    $this->get('/articles/hello-world');
    $fragment = Kami::remember('listing', 60, fn () => 'before');
    retitleBehindKamisBack('Unseen');

    $this->artisan($command)->assertSuccessful();

    expect($fragment)->toBe('before')
        ->and(File::directories(storage_path('framework/kami/pages')))->toBe([])
        ->and(Kami::remember('listing', 60, fn () => 'after'))->toBe('after');
    $this->get('/articles/hello-world')->assertSee('Unseen');
})->with([
    'kami:cache:clear',
    // The other tasks would empty the skeleton's bootstrap cache and compiled views, which parallel processes share.
    'optimize:clear' => 'optimize:clear --except=config,compiled,events,routes,views',
]);

it('makes every page stale on Kami::flushCache()', function () {
    cachedArticle();
    $this->get('/articles/hello-world');
    retitleBehindKamisBack('Unseen');

    Kami::flushCache();

    $this->get('/articles/hello-world')->assertSee('Unseen');
});

it('answers HEAD from a stored page, and never stores HEAD\'s empty body', function () {
    cachedArticle();

    $this->call('HEAD', '/articles/hello-world')->assertOk();
    $this->get('/articles/hello-world')->assertOk()->assertSee('Hello world');
    retitleBehindKamisBack('Unseen');

    $this->call('HEAD', '/articles/hello-world')->assertOk()->assertHeader('ETag');
    $this->get('/articles/hello-world')->assertSee('Hello world');
});

it('serves the page with no visibility query and nothing reported when storage/ cannot be written', function (string $readOnly) {
    cachedArticle();
    File::deleteDirectory(storage_path('framework/kami'));
    File::ensureDirectoryExists($directory = storage_path($readOnly));
    chmod($directory, 0555);
    Exceptions::fake();
    DB::enableQueryLog();

    $this->get('/articles/hello-world')->assertOk()->assertSee('Hello world');

    chmod($directory, 0755);
    expect(array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'min(')))->toBe([]);
    Exceptions::assertNothingReported();
})->with([
    'its directory' => 'framework/kami/pages',
    'a parent, the directory missing' => 'framework/kami',
]);

it('runs each fragment of Kami::remember() uncached, storing nothing, when storage/ cannot be written', function () {
    $pages = storage_path('framework/kami/pages');
    File::ensureDirectoryExists($pages);
    File::delete("{$pages}/manifest.json");
    chmod($pages, 0555);
    $calls = 0;
    $fragment = function () use (&$calls) {
        return ++$calls;
    };
    Event::fake([KeyWritten::class]);

    $first = Kami::remember('listing', 60, $fragment);
    // A request of its own: the version is read once per request.
    $this->app->instance('request', Request::create('/'));
    $second = Kami::remember('listing', 60, $fragment);

    chmod($pages, 0755);
    expect([$first, $second])->toBe([1, 2]);
    Event::assertNotDispatched(KeyWritten::class);
});

// A queue worker's request lives across its jobs: each read in the console sees a publish made by another process (#442).
it('reads the Content version from the manifest at each call in the console', function () {
    $first = PageCache::version();

    File::put(storage_path('framework/kami/pages/manifest.json'), json_encode(['version' => 'f0f0f0f0f0f0f0f0', 'next_change_at' => null]));

    expect($first)->toBeString()->not->toBe('f0f0f0f0f0f0f0f0')
        ->and(PageCache::version())->toBe('f0f0f0f0f0f0f0f0');
});

it('answers a moved address with its 301 at once, never the 200 it stored there', function () {
    $entry = cachedArticle();
    $this->get('/articles/hello-world')->assertOk();

    resolve(SaveEntry::class)->handle(Article::class, ['slug' => 'hello-again'], $entry);

    $this->get('/articles/hello-world')->assertMovedPermanently();
});
