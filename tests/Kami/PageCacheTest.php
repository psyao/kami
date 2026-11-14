<?php

declare(strict_types=1);

use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\Livewire;
use Psyao\Kami\Content\Actions\DuplicateEntry;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Actions\TrashEntry;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Internal\ReorderEntries;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Media\Actions\SaveMedia;
use Psyao\Kami\Routing\Internal\PageCache;
use Symfony\Component\HttpFoundation\Cookie;
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

/** A Livewire component as a site renders one on its front. */
final class Clicker extends Component
{
    public function render(): string
    {
        return '<button type="button" wire:click="$refresh">Click</button>';
    }
}

/** A global middleware of the application, setting its cookie once the `kami` group has returned. */
final class SetsConsentCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->setCookie(Cookie::create('consent', '1'));

        return $response;
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
    $first = $this->get('/articles/hello-world')->assertHeaderMissing('ETag');
    $hit = $this->get('/articles/hello-world')->assertOk();
    $etag = $hit->headers->get('ETag');

    // `kami:bench` tells a hit by this pattern.
    expect($etag)->toMatch(PageCache::ETAG)
        ->and($hit->headers->get('Cache-Control'))->toContain('no-cache')->toContain('private')
        ->and($hit->headers->get('Content-Security-Policy'))->toBe($first->headers->get('Content-Security-Policy'))
        ->and($hit->headers->get('Content-Type'))->toBe($first->headers->get('Content-Type'));

    $this->get('/articles/hello-world', ['If-None-Match' => $etag])->assertStatus(304)->assertContent('');
    // Each response gets its own date; the page cache stores none.
    expect(json_decode((string) strtok((string) file_get_contents(glob("{$this->storage}/framework/kami/pages/*/*")[0]), "\n"), true)['headers'])
        ->toHaveKey('Content-Type')->not->toHaveKey('Date');
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

it('serves an Archive\'s first page rendered once until a core write, and never stores a cursor page', function () {
    $this->freezeTime();
    foreach (range(1, 11) as $number) {
        cachedArticle(['title' => "Article {$number}", 'published_at' => now()->subDays($number)]);
    }
    $next = (string) str((string) $this->get('/articles')->assertOk()->getContent())->match('/href="([^"]+)" rel="next"/');
    $this->get(html_entity_decode($next))->assertOk()->assertSee('Article 11');

    retitleBehindKamisBack('Unseen');

    $this->get('/articles')->assertOk()->assertHeader('ETag')->assertSee('Article 1')->assertDontSee('Unseen');
    $this->get(html_entity_decode($next))->assertOk()->assertHeaderMissing('ETag')->assertSee('Unseen');
    cachedArticle(['title' => 'Article 12']);
    $this->get('/articles')->assertOk()->assertSee('Unseen');
});

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

it('never stores a response a global middleware sets a cookie on', function () {
    app(HttpKernel::class)->pushMiddleware(SetsConsentCookie::class);
    cachedArticle();
    $this->get('/articles/hello-world')->assertOk()->assertCookie('consent', '1', encrypted: false);

    retitleBehindKamisBack('Unseen');

    $this->get('/articles/hello-world')->assertSee('Unseen');
});

it('stores the page as sent, with the script and style Livewire injects once the pipeline has returned', function () {
    Livewire::component('clicker', Clicker::class);
    config(['kami.types' => [Article::class, Notice::class]]);
    Notice::$render = fn () => response(Blade::render('<html><head></head><body><livewire:clicker /></body></html>'));
    resolve(SaveEntry::class)->handle(Notice::class, ['title' => 'Hello world', 'status' => 'published']);

    $first = $this->get('/notice/hello-world')->assertOk();
    $hit = $this->get('/notice/hello-world')->assertOk()->assertHeader('ETag');

    expect($first->getContent())->toContain('<script', '<style')
        ->and($hit->getContent())->toBe($first->getContent());
});

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

it('renders a page it could not store on every request, never as a hit', function () {
    cachedArticle();
    $this->get('/articles/hello-world');
    $stored = File::allFiles(storage_path('framework/kami/pages/' . PageCache::version()));
    File::delete($stored[0]->getPathname());
    chmod($directory = $stored[0]->getPath(), 0555);

    $this->get('/articles/hello-world')->assertOk();
    $response = $this->get('/articles/hello-world');

    chmod($directory, 0755);
    $response->assertOk()->assertSee('Hello world')->assertHeaderMissing('ETag');
});

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

it('keeps Inertia\'s JSON answer as a copy of its own beside the HTML, each served to its own request', function () {
    inertiaPage();
    $html = $this->get('/inertia_page/hello-world')->assertOk();
    $json = $this->get('/inertia_page/hello-world', inertiaVisit())->assertOk()->assertHeader('X-Inertia', 'true');

    retitleBehindKamisBack('Unseen');

    $this->get('/inertia_page/hello-world', inertiaVisit())->assertOk()->assertHeader('ETag')
        ->assertHeader('Content-Type', 'application/json')->assertContent($json->getContent());
    $this->get('/inertia_page/hello-world')->assertOk()->assertHeader('ETag')->assertContent($html->getContent());
    expect(File::allFiles(storage_path('framework/kami/pages/' . PageCache::version())))->toHaveCount(2);
});

it('keeps Inertia\'s JSON answer of an Archive beside its HTML', function () {
    inertiaPage();
    $json = $this->get('/inertia_page', inertiaVisit())->assertOk()->assertHeader('X-Inertia', 'true');

    retitleBehindKamisBack('Unseen');

    $this->get('/inertia_page', inertiaVisit())->assertOk()->assertHeader('ETag')->assertContent($json->getContent());
    expect($json->json('props.titles'))->toBe(['Hello world']);
});

// Without Inertia's middleware, no 409 refuses a forged version: each would fill the disk with a copy of its own.
it('never stores Inertia\'s JSON under an asset version it was not answered to', function () {
    inertiaPage(middleware: false);
    $this->get('/inertia_page/hello-world', inertiaVisit('forged'))->assertOk()->assertHeader('X-Inertia', 'true');
    retitleBehindKamisBack('Unseen');

    $this->get('/inertia_page/hello-world', inertiaVisit('forged'))->assertSee('Unseen')->assertHeaderMissing('ETag');
});

it('never stores or serves an Inertia request that asks for part of the page', function (string $header, string $value) {
    inertiaPage();
    $partial = [...inertiaVisit(), $header => $value];
    $this->get('/inertia_page/hello-world', $partial)->assertOk();
    retitleBehindKamisBack('Unseen');

    $this->get('/inertia_page/hello-world', inertiaVisit())->assertSee('Unseen');

    retitleBehindKamisBack('Unserved');

    $this->get('/inertia_page/hello-world', $partial)->assertSee('Unserved');
})->with([
    'one component' => ['X-Inertia-Partial-Component', 'Page'],
    'only some props' => ['X-Inertia-Partial-Data', 'title'],
    'all props but some' => ['X-Inertia-Partial-Except', 'errors'],
    'props reset' => ['X-Inertia-Reset', 'title'],
]);

it('never answers an Inertia request with the HTML of a page that is not Inertia\'s', function () {
    inertiaPage();
    cachedArticle();
    $this->get('/articles/hello-world')->assertOk();
    $this->get('/articles/hello-world', inertiaVisit())->assertOk();
    retitleBehindKamisBack('Unseen');

    $this->get('/articles/hello-world', inertiaVisit())->assertSee('Unseen')->assertHeaderMissing('ETag');
});
