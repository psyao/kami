<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Facades\Kami;

uses(RefreshDatabase::class);

/** psyao.ch's Posts: a Feed under `articles`. */
final class Dispatch extends ContentType
{
    #[Override]
    protected ?string $prefix = 'articles';

    #[Override]
    protected bool $feed = true;

    #[Override]
    public function summary(Entry $entry): ?string
    {
        return "Le début de {$entry->title} & la suite.";
    }
}

/** A second type with a Feed, mixed with the first at `/feed`. */
final class Newsletter extends ContentType
{
    #[Override]
    protected ?string $prefix = 'news';

    #[Override]
    protected bool $feed = true;
}

/** A tree with a Feed: a child under a hidden Parent stays out. */
final class Dossier extends ContentType
{
    #[Override]
    protected bool $hierarchical = true;

    #[Override]
    protected bool $feed = true;
}

beforeEach(function () {
    config(['kami.types' => [Dispatch::class, Newsletter::class, Dossier::class]]);
    Kami::routes();
    $this->freezeTime();
});

/** @param array<string, mixed> $values */
function saveDispatch(string $title, int $daysAgo, array $values = []): Entry
{
    return resolve(SaveEntry::class)->handle(Dispatch::class, ['title' => $title, 'status' => 'published', 'published_at' => now()->subDays($daysAgo), ...$values]);
}

/**
 * One element of each item, in order.
 *
 * @return list<string>
 */
function items(SimpleXMLElement $xml, string $element = 'title'): array
{
    return array_map(fn (SimpleXMLElement $item): string => (string) $item->{$element}, iterator_to_array($xml->channel->item, false));
}

/** The Feed's XML, parsed. */
function feed(string $uri): SimpleXMLElement
{
    $response = test()->get($uri)->assertOk()->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8');

    return new SimpleXMLElement((string) $response->getContent());
}

it('serves a type\'s 20 latest visible Entries as RSS 2.0', function () {
    saveDispatch('Premier', 30);
    foreach (range(1, 21) as $day) {
        saveDispatch("Billet {$day}", $day);
    }
    saveDispatch('Brouillon', 0, ['status' => 'draft']);

    $xml = feed('/articles/feed');

    expect((string) $xml['version'])->toBe('2.0')
        ->and((string) $xml->channel->title)->not->toBe('')
        ->and((string) $xml->channel->link)->toBe('http://localhost')
        ->and((string) $xml->channel->description)->not->toBe('')
        ->and((string) $xml->channel->language)->toBe('fr')
        ->and($xml->channel->item)->toHaveCount(20)
        ->and((string) $xml->channel->item[0]->title)->toBe('Billet 1')
        ->and((string) $xml->channel->item[0]->link)->toBe('http://localhost/articles/billet-1')
        ->and((string) $xml->channel->item[0]->pubDate)->toBe(now()->subDay()->toRssString())
        ->and((string) $xml->channel->item[19]->title)->toBe('Billet 20')
        ->and(items($xml))->not->toContain('Brouillon', 'Premier');
});

it('identifies an item by its ULID across a slug change, and describes it by its summary', function () {
    $entry = saveDispatch('Avant', 1);
    $before = feed('/articles/feed')->channel->item[0];

    resolve(SaveEntry::class)->handle(Dispatch::class, ['title' => 'Après', 'slug' => 'apres', 'status' => 'published'], $entry);
    $after = feed('/articles/feed')->channel->item[0];

    expect((string) $before->guid)->toBe($entry->id)->toBe((string) $after->guid)
        ->and((string) $before->guid['isPermaLink'])->toBe('false')
        ->and((string) $after->link)->toBe('http://localhost/articles/apres')
        ->and((string) $after->description)->toBe('Le début de Après & la suite.');
});

it('mixes every type with a Feed at /feed, and serves each Locale under its segment', function () {
    $dispatch = saveDispatch('Bonjour', 2);
    resolve(SaveEntry::class)->handle(Dispatch::class, ['title' => 'Hello', 'status' => 'published'], $dispatch, locale: 'en');
    resolve(SaveEntry::class)->handle(Newsletter::class, ['title' => 'Lettre', 'status' => 'published', 'published_at' => now()->subDay()]);

    $site = feed('/feed');
    $english = feed('/en/articles/feed');

    expect(items($site))->toBe(['Lettre', 'Bonjour'])
        ->and((string) $site->channel->link)->toBe('http://localhost')
        ->and((string) $english->channel->language)->toBe('en')
        ->and($english->channel->item)->toHaveCount(1)
        ->and((string) $english->channel->item[0]->link)->toBe('http://localhost/en/articles/hello');
    $this->get('/en/feed')->assertOk()->assertSee('http://localhost/en/articles/hello');
});

it('serves an empty Feed, and leaves out an Entry under a hidden Parent', function () {
    $hidden = resolve(SaveEntry::class)->handle(Dossier::class, ['title' => 'Caché', 'status' => 'draft']);
    resolve(SaveEntry::class)->handle(Dossier::class, ['title' => 'Orphelin', 'status' => 'published', 'parent' => $hidden->id]);
    $parent = resolve(SaveEntry::class)->handle(Dossier::class, ['title' => 'Parent', 'status' => 'published']);
    resolve(SaveEntry::class)->handle(Dossier::class, ['title' => 'Enfant', 'status' => 'published', 'parent' => $parent->id]);

    expect(feed('/articles/feed')->channel->item)->toHaveCount(0)
        ->and(items(feed('/dossier/feed'), 'link'))->toEqualCanonicalizing(['http://localhost/dossier/parent', 'http://localhost/dossier/parent/enfant']);
});

it('drops the characters XML 1.0 forbids, and escapes the rest', function () {
    saveDispatch("Sauts\u{0B} <b>& co</b>", 1);

    expect((string) feed('/articles/feed')->channel->item[0]->title)->toBe('Sauts <b>& co</b>');
});

it('leaves a Path ending in feed to its Entry when its type offers no Feed', function () {
    expect(Route::getRoutes()->match(Request::create('/pages/feed'))->isFallback)->toBeTrue()
        ->and(Route::getRoutes()->match(Request::create('/en/news/feed'))->isFallback)->toBeFalse();
});

it('serves a Feed in an exact number of queries, whatever the volume', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        feed('/feed');
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    saveDispatch('Un', 1);
    $small = $count();

    foreach (range(2, 25) as $day) {
        saveDispatch("Billet {$day}", $day);
    }

    // The Site settings, the page, its Translations.
    expect($count())->toBe($small)->toBe(3);
});
