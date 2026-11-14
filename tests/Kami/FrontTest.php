<?php

declare(strict_types=1);

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Psyao\Kami\Content\Actions\DeleteEntry;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Actions\TrashEntry;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\SiteSettings;
use Psyao\Kami\Facades\Kami;
use Symfony\Component\HttpFoundation\Response;
use Workbench\App\Content\Article;
use Workbench\App\Content\Project;

uses(RefreshDatabase::class);

/** A type whose `render()` takes over, with a dependency injected by the container. */
final class Bulletin extends ContentType
{
    public function render(Entry $entry, ?Request $request = null): View | Response
    {
        return response("{$entry->title} at /{$request?->path()}", 202);
    }
}

/** A site's rendering middleware, as `Kami::routes(middleware:)` takes one, marking each response it sees. */
final class MarksRendering
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Rendered-By', 'site');

        return $response;
    }
}

beforeEach(function () {
    Kami::routes();
});

/** @param array<string, mixed> $values */
function saveArticle(array $values = []): Entry
{
    return resolve(SaveEntry::class)->handle(Article::class, [
        'title' => 'Hello world',
        'status' => 'published',
        'summary' => 'A first article.',
        'author' => 'Ada Lovelace',
        ...$values,
    ]);
}

it('renders a published Entry at its Path through the type view', function () {
    saveArticle();

    $this->get('/articles/hello-world')
        ->assertOk()
        ->assertSee('<h1>Hello world</h1>', escape: false)
        ->assertSee('>A first article.</p>', escape: false);
});

it('answers 404 for a Draft, a future publication date and an unknown Path', function () {
    saveArticle(['title' => 'Draft', 'status' => 'draft']);
    saveArticle(['title' => 'Later', 'published_at' => now()->addDay()]);

    $this->get('/articles/draft')->assertNotFound();
    $this->get('/articles/later')->assertNotFound();
    $this->get('/articles/nowhere')->assertNotFound();
});

it('lets a host route on the same address win', function () {
    saveArticle();
    Route::get('/articles/hello-world', fn () => 'host');

    $this->get('/articles/hello-world')->assertOk()->assertSeeText('host');
});

it('calls an overridden render() with its injected dependencies', function () {
    config(['kami.types' => [Bulletin::class]]);
    resolve(SaveEntry::class)->handle(Bulletin::class, ['title' => 'Notice', 'status' => 'published']);

    $this->get('/bulletin/notice')->assertStatus(202)->assertSeeText('Notice at /bulletin/notice');
});

it('escapes markup in a title and a Field value', function () {
    saveArticle(['title' => '<script>alert(1)</script>', 'slug' => 'markup', 'summary' => '<b>bold</b>']);

    $this->get('/articles/markup')
        ->assertOk()
        ->assertDontSee('<script>', escape: false)
        ->assertDontSee('<b>', escape: false)
        ->assertSee('&lt;b&gt;bold&lt;/b&gt;', escape: false);
});

it('renders an Entry page in an exact number of queries', function () {
    saveArticle();
    resolve(SaveEntry::class)->handle(SiteSettings::class, ['site_name' => 'Kami'], locale: 'fr');
    expect(Model::preventsLazyLoading())->toBeTrue();

    DB::enableQueryLog();
    $this->get('/articles/hello-world')->assertOk();

    // The Path, the Entry with its visibility, its Translations, its type rows; the head's Site settings, their Translations and type rows,
    // the Entry's Social image Reference, then the Site's default one.
    expect(DB::getQueryLog())->toHaveCount(9);
});

it('renders related Entries in pick order, a Draft left out, in an exact number of queries', function () {
    $first = saveArticle(['title' => 'First']);
    $draft = saveArticle(['title' => 'Hidden', 'status' => 'draft']);
    $last = saveArticle(['title' => 'Last']);
    resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Kami', 'status' => 'published', 'related' => [$last->id, $draft->id, $first->id]]);

    DB::enableQueryLog();
    $response = $this->get('/project/kami')->assertOk()->assertSeeInOrder(['Last', 'First'])->assertDontSee('Hidden');

    // The page's four, the rows, the targets, their Translations, the Articles' rows, the view's `tags` rows, the head's look for the Site settings,
    // its Social image Reference, then the `cover` rows its type's `socialImage()` reads, which the view finds loaded.
    expect(DB::getQueryLog())->toHaveCount(12);
    $response->assertSee('/articles/last');
});

it('sets no cookie on a front response, even inside the web group', function () {
    saveArticle();
    Route::middleware('web')->group(fn () => Kami::routes());

    $this->get('/articles/hello-world')->assertOk()->assertHeaderMissing('Set-Cookie');
    $this->get('/articles/nowhere')->assertNotFound()->assertHeaderMissing('Set-Cookie');
});

it('runs the site\'s middleware on an Entry, after the kami group, on either mount', function (bool $session) {
    saveArticle();
    Kami::routes(session: $session, middleware: [MarksRendering::class]);

    $this->get('/articles/hello-world')->assertOk()->assertHeader('X-Rendered-By', 'site');
    $middleware = Route::getRoutes()->match(Request::create('/articles/hello-world'))->gatherMiddleware();
    expect(array_search(MarksRendering::class, $middleware, true))->toBeGreaterThan(array_search('kami', $middleware, true));
})->with(['sessionless' => [false], 'with the session' => [true]]);

// `route:cache` keeps the route's own middleware but neither the `kami` group nor its alias, which `KamiServiceProvider` declares at boot.
it('keeps the site\'s middleware on an Entry read from a cached route table', function () {
    saveArticle();
    Kami::routes(middleware: [MarksRendering::class]);
    foreach (Route::getRoutes() as $route) {
        $route->prepareForSerialization();
    }
    Route::setCompiledRoutes(Route::getRoutes()->compile());

    $this->get('/articles/hello-world')->assertOk()->assertHeader('X-Rendered-By', 'site');
});

it('keeps the site\'s middleware off the Tick, Conversions, the Form route, Preview links and the sitemap', function (bool $session, string $method, string $uri) {
    Kami::routes(session: $session, middleware: [MarksRendering::class]);

    expect(Route::getRoutes()->match(Request::create($uri, $method))->gatherMiddleware())->not->toContain(MarksRendering::class);
})->with(['sessionless' => [false], 'with the session' => [true]])->with([
    'the Tick' => ['POST', '/kami/tick/token'],
    'a Conversion' => ['GET', '/media/1/abc/photo.jpg'],
    'an SVG' => ['GET', '/media/1/abc/logo.svg'],
    'the Form route' => ['POST', '/kami/forms/contact'],
    'a Preview link' => ['GET', '/kami/share/token'],
    'the sitemap' => ['GET', '/sitemap.xml'],
    'a sitemap file' => ['GET', '/sitemaps/fr-1.xml'],
]);

it('tells addresses apart by their bytes on every engine', function () {
    saveArticle(['title' => 'Cafe', 'slug' => 'cafe', 'summary' => 'Plain']);
    $accented = saveArticle(['title' => 'Café', 'slug' => "caf\u{e9}", 'summary' => 'Accented']);

    expect($accented->slug)->toBe("caf\u{e9}");
    $this->get('/articles/caf%C3%A9')->assertOk()->assertSee('Accented');
    $this->get('/articles/cafe')->assertOk()->assertSee('Plain');
    $this->get('/articles/cafe%20')->assertNotFound();
    $this->get('/articles/%C3%A7afe')->assertNotFound();
});

describe('a moved address', function () {
    it('answers 301 to the new address once public, which carries the canonical URL', function () {
        $entry = saveArticle();

        resolve(SaveEntry::class)->handle(Article::class, ['slug' => 'hello-again'], $entry);

        $this->get('/articles/hello-world')->assertMovedPermanently()->assertRedirect('http://localhost/articles/hello-again');
        $this->get('/articles/hello-again')->assertOk()->assertSee('<link rel="canonical" href="http://localhost/articles/hello-again">', escape: false);
    });

    it('rewrites a never-public address in place: a Draft, or a publication still to come', function (array $values) {
        $entry = saveArticle($values);

        resolve(SaveEntry::class)->handle(Article::class, ['slug' => 'hello-again'], $entry);

        expect(DB::table('kami_paths')->pluck('path')->all())->toBe(['articles/hello-again']);
    })->with([
        'a Draft' => [['status' => 'draft']],
        'a publication to come' => [['published_at' => now()->addDay()]],
    ]);

    it('keeps it for an unpublished Translation, answering once it is visible again', function () {
        $entry = saveArticle();
        resolve(SaveEntry::class)->handle(Article::class, ['status' => 'draft'], $entry);

        resolve(SaveEntry::class)->handle(Article::class, ['slug' => 'hello-again'], $entry);
        $this->get('/articles/hello-world')->assertNotFound();

        resolve(SaveEntry::class)->handle(Article::class, ['status' => 'published'], $entry);
        $this->get('/articles/hello-world')->assertMovedPermanently()->assertRedirect('http://localhost/articles/hello-again');
    });

    it('keeps it for a published Translation rescheduled to the future, then renamed in a later save', function () {
        $entry = saveArticle();
        resolve(SaveEntry::class)->handle(Article::class, ['published_at' => now()->addDay()], $entry);

        resolve(SaveEntry::class)->handle(Article::class, ['slug' => 'hello-again'], $entry);

        $this->travel(2)->days();
        $this->get('/articles/hello-world')->assertMovedPermanently()->assertRedirect('http://localhost/articles/hello-again');
    });

    it('gives an address back to the Entry moving back to it, the one it leaves redirecting', function () {
        $entry = saveArticle();

        resolve(SaveEntry::class)->handle(Article::class, ['slug' => 'hello-again'], $entry);
        resolve(SaveEntry::class)->handle(Article::class, ['slug' => 'hello-world'], $entry);

        $this->get('/articles/hello-world')->assertOk();
        $this->get('/articles/hello-again')->assertMovedPermanently()->assertRedirect('http://localhost/articles/hello-world');
    });

    it('lets another Entry take a moved address, replacing its Redirect', function () {
        $entry = saveArticle();
        resolve(SaveEntry::class)->handle(Article::class, ['slug' => 'hello-again'], $entry);

        saveArticle(['title' => 'Other', 'slug' => 'hello-world']);

        $this->get('/articles/hello-world')->assertOk()->assertSee('<h1>Other</h1>', escape: false);
    });

    it('takes a browser\'s cached 301 of a deleted Entry on to its replacement moved since, in one redirect (#287)', function () {
        $deleted = saveArticle(['title' => 'Old']);
        $replacement = saveArticle();
        resolve(TrashEntry::class)->handle($deleted);
        resolve(DeleteEntry::class)->handle(Entry::query()->withTrashed()->findOrFail($deleted->id), $replacement);
        $cached = $this->get('/articles/old')->assertMovedPermanently()->headers->get('Location');

        resolve(SaveEntry::class)->handle(Article::class, ['slug' => 'hello-again'], $replacement);

        $this->get((string) parse_url((string) $cached, PHP_URL_PATH))->assertMovedPermanently()->assertRedirect('http://localhost/articles/hello-again');
        $this->get('/articles/old')->assertMovedPermanently()->assertRedirect('http://localhost/articles/hello-again');
    });
});
