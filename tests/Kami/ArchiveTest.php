<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Terms;
use Psyao\Kami\Content\Internal\PreviewLink;
use Psyao\Kami\Content\Taxonomy;
use Psyao\Kami\Content\Term;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Media\Actions\SaveMedia;
use Psyao\Kami\Routing\Internal\RenderEntry;

uses(RefreshDatabase::class);

/** psyao.ch's Posts: listed under `articles`, two a page. */
final class Post extends ContentType
{
    #[Override]
    protected ?string $prefix = 'articles';

    #[Override]
    protected bool $archive = true;

    #[Override]
    protected int $perPage = 2;

    public function fields(): array
    {
        return [
            Terms::make('categories')->of(Category::class),
            Terms::make('keywords')->of(Keyword::class),
        ];
    }
}

/** A hierarchical Taxonomy with an address, as psyao.ch's Categories. */
final class Category extends Taxonomy
{
    #[Override]
    protected bool $hierarchical = true;

    #[Override]
    protected int $perPage = 2;
}

/** A flat Taxonomy with an address, as psyao.ch's Tags. */
final class Keyword extends Taxonomy
{
    #[Override]
    protected ?string $prefix = 'tag';

    #[Override]
    protected int $perPage = 2;
}

/** A hand-ordered tree with an Archive: listed in Position order, a child under a hidden Parent left out. */
final class Chapter extends ContentType
{
    #[Override]
    protected bool $archive = true;

    #[Override]
    protected bool $orderable = true;

    #[Override]
    protected bool $hierarchical = true;
}

/** A type whose links to Terms differ by Locale. */
final class Tour extends ContentType
{
    public function fields(): array
    {
        return [Terms::make('stops')->of(Keyword::class)->translatable()];
    }
}

beforeEach(function () {
    config(['kami.types' => [Post::class, Category::class, Keyword::class, Chapter::class]]);
    View::addLocation(dirname(__DIR__) . '/Fixtures/archive');
    Kami::routes();
    $this->freezeTime();
});

/** @param array<string, mixed> $values */
function savePost(string $title, int $daysAgo, array $values = []): Entry
{
    return resolve(SaveEntry::class)->handle(Post::class, ['title' => $title, 'status' => 'published', 'published_at' => now()->subDays($daysAgo), ...$values]);
}

it('lists the visible Entries at the Prefix, newest first, page by page both ways', function () {
    savePost('Oldest', 5);
    savePost('Old', 4);
    savePost('Draft', 3, ['status' => 'draft']);
    savePost('Middle', 3);
    savePost('New', 2);
    savePost('Newest', 1);
    savePost('Scheduled', -1);

    $first = $this->get('/articles')->assertOk()->assertSeeInOrder(['Newest', 'New'])->assertDontSee('Middle');
    $next = (string) str($first->getContent())->match('/href="([^"]+)" rel="next"/');
    $second = $this->get(html_entity_decode($next))->assertOk()->assertSeeInOrder(['Middle', 'Old'])->assertDontSee('Newest')->assertDontSee('Draft');
    $third = $this->get(html_entity_decode((string) str($second->getContent())->match('/href="([^"]+)" rel="next"/')))
        ->assertOk()->assertSee('Oldest')->assertDontSee('rel="next"', escape: false)->assertDontSee('Scheduled');
    $back = html_entity_decode((string) str($third->getContent())->match('/href="([^"]+)" rel="prev"/'));

    $this->get($back)->assertOk()->assertSeeInOrder(['Middle', 'Old'])->assertDontSee('Oldest');
});

/** The `href` of the page's link with this `rel`, as a path to request. */
function pageLink(TestResponse $response, string $rel): string
{
    return html_entity_decode((string) str((string) $response->getContent())->match("/href=\"([^\"]+)\" rel=\"{$rel}\"/"));
}

it('answers 404 for a malformed cursor and for a page past the last', function (string $cursor) {
    savePost('Only', 1);

    $this->get("/articles?cursor={$cursor}")->assertNotFound();
})->with([
    'not base64 JSON' => ['garbage'],
    'another listing\'s parameters' => [rtrim(strtr(base64_encode((string) json_encode(['id' => 'x', '_pointsToNextItems' => true])), '+/', '-_'), '=')],
    'a non-scalar value' => [rtrim(strtr(base64_encode((string) json_encode(['kami__published_at' => ['x'], 'kami_entries.id' => 'x', '_pointsToNextItems' => true])), '+/', '-_'), '=')],
    'an array' => ['[]=x'],
    'past the last' => [rtrim(strtr(base64_encode((string) json_encode(['kami__published_at' => '2000-01-01 00:00:00', 'kami_entries.id' => '0', '_pointsToNextItems' => true])), '+/', '-_'), '=')],
]);

it('renders an empty first page', function () {
    $this->get('/articles')->assertOk()->assertDontSee('kami-pagination');
});

it('lists each Locale\'s visible Translations under its segment', function () {
    $post = savePost('Bonjour', 2);
    resolve(SaveEntry::class)->handle(Post::class, ['title' => 'Hello', 'status' => 'published'], $post, locale: 'en');
    savePost('Seulement', 1);

    $this->get('/articles')->assertOk()->assertSeeInOrder(['Seulement', 'Bonjour']);
    $this->get('/en/articles')->assertOk()->assertSee('Hello')->assertSee('http://localhost/en/articles/hello')->assertDontSee('Seulement');
});

it('gives the first page a canonical and language links, and a cursor page noindex with neither', function () {
    config(['kami.indexable' => true]);
    foreach (range(1, 3) as $day) {
        savePost("Post {$day}", $day);
    }

    $first = $this->get('/articles')->assertOk()
        ->assertSee('<title>Posts', escape: false)
        ->assertSee('<link rel="canonical" href="http://localhost/articles">', escape: false)
        ->assertSee('hreflang="en" href="http://localhost/en/articles"', escape: false)
        ->assertDontSee('name="robots"', escape: false);

    $this->get(pageLink($first, 'next'))->assertOk()
        ->assertSee('<meta name="robots" content="noindex, follow">', escape: false)
        ->assertDontSee('rel="canonical"', escape: false)
        ->assertDontSee('hreflang', escape: false);
});

it('lists a hand-ordered type in Position order, leaving out an Entry under a hidden Parent', function () {
    $save = fn (string $title, array $values = []): Entry => resolve(SaveEntry::class)->handle(Chapter::class, ['title' => $title, 'status' => 'published', ...$values]);
    $first = $save('First');
    $hidden = $save('Hidden', ['status' => 'draft']);
    $save('Orphan', ['parent' => $hidden->id]);
    $save('Child', ['parent' => $first->id]);

    $this->get('/chapter')->assertOk()->assertSeeInOrder(['First', 'Child'])->assertSee('http://localhost/chapter/first/child')->assertDontSee('Orphan');
});

describe('a Term Archive', function () {
    it('lists the visible Entries linking a Category, newest first, page by page', function () {
        $music = resolve(SaveEntry::class)->handle(Category::class, ['title' => 'Music']);
        $jazz = resolve(SaveEntry::class)->handle(Category::class, ['title' => 'Jazz', 'parent' => $music->id]);
        savePost('Unfiled', 1);
        savePost('Old', 4, ['categories' => [$jazz->id]]);
        savePost('Middle', 3, ['categories' => [$jazz->id]]);
        savePost('Draft', 2, ['categories' => [$jazz->id], 'status' => 'draft']);
        savePost('New', 1, ['categories' => [$music->id, $jazz->id]]);

        $first = $this->get('/category/music/jazz')->assertOk()->assertSee('<h1>Jazz</h1>', escape: false)->assertSeeInOrder(['New', 'Middle'])->assertDontSee('Unfiled');

        $this->get(pageLink($first, 'next'))->assertOk()->assertSee('Old')->assertDontSee('Middle')->assertDontSee('Draft');
        $this->get('/category/music')->assertOk()->assertSee('New')->assertDontSee('Old');
    });

    it('lists the Entries linking a Tag, and gives a cursor page noindex', function () {
        config(['kami.indexable' => true]);
        $live = resolve(SaveEntry::class)->handle(Keyword::class, ['title' => 'Live']);
        foreach (range(1, 3) as $day) {
            savePost("Concert {$day}", $day, ['keywords' => [$live->id]]);
        }

        $first = $this->get('/tag/live')->assertOk()->assertSeeInOrder(['Concert 1', 'Concert 2'])
            ->assertSee('<link rel="canonical" href="http://localhost/tag/live">', escape: false);

        $this->get(pageLink($first, 'next'))->assertOk()->assertSee('Concert 3')
            ->assertSee('<meta name="robots" content="noindex, follow">', escape: false)
            ->assertDontSee('rel="canonical"', escape: false);
        $this->get('/tag/live?cursor=garbage')->assertNotFound();
    });
});

it('renders an Archive page and a Term Archive page in an exact number of queries, whatever the volume', function () {
    $live = resolve(SaveEntry::class)->handle(Keyword::class, ['title' => 'Live']);
    $count = function (string $uri): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($uri)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    savePost('One', 2, ['keywords' => [$live->id]]);
    $small = [$count('/articles'), $count('/tag/live')];

    foreach (range(3, 8) as $day) {
        savePost("Post {$day}", $day, ['keywords' => [$live->id]]);
    }

    // The Archive: its page, the Translations, the head's look for the Site settings. The Term Archive: the Path, the Term, its Translations,
    // the Site settings, the Term's Social image Reference, then the page and its Translations.
    expect([$count('/articles'), $count('/tag/live')])->toBe($small)->toBe([3, 7]);
});

it('gives a Term\'s Preview the first page of its Entries, as visitors see them', function () {
    $live = resolve(SaveEntry::class)->handle(Keyword::class, ['title' => 'Live', 'status' => 'draft']);
    savePost('Concert', 1, ['keywords' => [$live->id]]);
    savePost('Rehearsal', 2, ['keywords' => [$live->id], 'status' => 'draft']);

    $this->get(PreviewLink::issue($live, 'fr', 7, null) . '?cursor=garbage')
        ->assertOk()->assertSee('<h1>Live</h1>', escape: false)->assertSee('Concert')->assertDontSee('Rehearsal');
});

// A Term never saved has no id, as the admin Preview's; the Preview's own setup needs the type's Filament routes, registered at boot.
it('gives a Term never saved an empty page, whatever links Media', function () {
    Storage::fake('local');
    savePost('Illustrated', 1, ['seo_image' => resolve(SaveMedia::class)->handle(fixture('media/pixel.png'))->id]);

    $view = RenderEntry::call(new Keyword, (new Term)->forceFill(['type' => 'keyword', 'site' => 'default']));

    expect($view->getData()['entries']->items())->toBe([]);
});

it('lists a Term\'s Entries by the links of the page\'s Locale, and Shared ones', function () {
    config(['kami.types' => [Post::class, Category::class, Keyword::class, Chapter::class, Tour::class]]);
    $live = resolve(SaveEntry::class)->handle(Keyword::class, ['title' => 'Live']);
    resolve(SaveEntry::class)->handle(Keyword::class, ['title' => 'Live'], $live, locale: 'en');
    $studio = resolve(SaveEntry::class)->handle(Keyword::class, ['title' => 'Studio']);
    $tour = resolve(SaveEntry::class)->handle(Tour::class, ['title' => 'Tournée', 'status' => 'published', 'stops' => [$live->id]]);
    resolve(SaveEntry::class)->handle(Tour::class, ['title' => 'Tour', 'status' => 'published', 'stops' => [$studio->id]], $tour, locale: 'en');
    $shared = savePost('Partagé', 1, ['keywords' => [$live->id]]);
    resolve(SaveEntry::class)->handle(Post::class, ['title' => 'Shared', 'status' => 'published'], $shared, locale: 'en');

    $this->get('/tag/live')->assertOk()->assertSee('Tournée')->assertSee('Partagé');
    $this->get('/en/tag/live')->assertOk()->assertDontSee('Tour<', escape: false)->assertSee('Shared');
});

it('pages a hand-ordered type both ways', function () {
    config(['kami.types' => [Post::class, Category::class, Keyword::class, Chapter::class]]);
    foreach (range(1, 12) as $number) {
        resolve(SaveEntry::class)->handle(Chapter::class, ['title' => "Chapter {$number}x", 'status' => 'published']);
    }

    $next = $this->get(pageLink($this->get('/chapter')->assertOk()->assertSeeInOrder(['Chapter 1x', 'Chapter 10x']), 'next'))
        ->assertOk()->assertSeeInOrder(['Chapter 11x', 'Chapter 12x'])->assertDontSee('Chapter 10x');

    $this->get(pageLink($next, 'prev'))->assertOk()->assertSee('Chapter 10x')->assertDontSee('Chapter 11x');
});

it('links on from a page whose every Entry sits under a hidden Parent', function () {
    $save = fn (string $title, array $values = []): Entry => resolve(SaveEntry::class)->handle(Chapter::class, ['title' => $title, 'status' => 'published', ...$values]);
    $hidden = $save('Hidden', ['status' => 'draft']);
    // Ten children at Positions 0 to 9 fill the first page, and every one is dropped.
    foreach (range(1, 10) as $number) {
        $save("Orphan {$number}", ['parent' => $hidden->id]);
    }
    $save('Last', ['position' => 100]);

    $first = $this->get('/chapter')->assertOk()->assertDontSee('Orphan');

    $this->get(pageLink($first, 'next'))->assertOk()->assertSee('Last');
});
