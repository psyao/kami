<?php

declare(strict_types=1);

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Facades\Kami;
use Symfony\Component\HttpFoundation\Response;
use Workbench\App\Content\Article;

uses(RefreshDatabase::class);

/** A type whose `render()` takes over, with a dependency injected by the container. */
final class Bulletin extends ContentType
{
    public function render(Entry $entry, ?Request $request = null): View | Response
    {
        return response("{$entry->title} at /{$request?->path()}", 202);
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
    expect(Model::preventsLazyLoading())->toBeTrue();

    DB::enableQueryLog();
    $this->get('/articles/hello-world')->assertOk();

    // The Path, the Entry with its visibility, its Translations, its type rows.
    expect(DB::getQueryLog())->toHaveCount(4);
});

it('sets no cookie on a front response, even inside the web group', function () {
    saveArticle();
    Route::middleware('web')->group(fn () => Kami::routes());

    $this->get('/articles/hello-world')->assertOk()->assertHeaderMissing('Set-Cookie');
    $this->get('/articles/nowhere')->assertNotFound()->assertHeaderMissing('Set-Cookie');
});
