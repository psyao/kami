<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Facades\Kami;
use Workbench\App\Content\Article;
use Workbench\App\Content\Label;
use Workbench\App\Content\Page;

uses(RefreshDatabase::class);

beforeEach(function () {
    Kami::routes();
});

/** @param array<string, mixed> $values */
function translate(string $locale, array $values, ?Entry $entry = null, string $type = Article::class): Entry
{
    return resolve(SaveEntry::class)->handle($type, ['status' => 'published', ...$values], $entry, $locale);
}

it('answers each Locale at its own Path, the default one unprefixed', function () {
    $entry = translate('fr', ['title' => 'Bonjour', 'summary' => 'Un article.']);
    translate('en', ['title' => 'Hello', 'summary' => 'An article.'], $entry);

    $this->get('/articles/bonjour')->assertOk()->assertSee('<html lang="fr">', escape: false)->assertSee('Un article.');
    $this->get('/en/articles/hello')->assertOk()->assertSee('<html lang="en">', escape: false)->assertSee('An article.');
    $this->get('/en/articles/bonjour')->assertNotFound();
    $this->get('/articles/hello')->assertNotFound();
    $this->get('/fr/articles/bonjour')->assertNotFound();
});

it('hides an Entry in a Locale where its Translation is missing or a Draft', function () {
    $both = translate('fr', ['title' => 'Les deux']);
    translate('en', ['title' => 'Both'], $both);
    translate('fr', ['title' => 'Seul']);
    $draft = translate('fr', ['title' => 'Brouillon']);
    translate('en', ['title' => 'Draft', 'status' => 'draft'], $draft);

    expect(Entry::query()->inLocale('en')->pluck('id')->all())->toBe([$both->id])
        ->and(Entry::query()->count())->toBe(3)
        ->and(Entry::query()->withUnpublished()->inLocale('en')->count())->toBe(2)
        ->and(Entry::query()->inLocale('en')->withUnpublished()->count())->toBe(2);

    $this->get('/en/articles/seul')->assertNotFound();
    $this->get('/en/articles/draft')->assertNotFound();
});

it('reads the Entries of inLocale() in that Locale', function () {
    $entry = translate('fr', ['title' => 'Bonjour', 'summary' => 'Un article.']);
    translate('en', ['title' => 'Hello', 'summary' => 'An article.'], $entry);

    $english = Entry::query()->inLocale('en')->sole();

    expect([$english->title, $english->summary])->toBe(['Hello', 'An article.'])
        ->and(Entry::query()->sole()->title)->toBe('Bonjour');
});

it('shows a Shared Field with one value in every Locale', function () {
    $entry = translate('fr', ['title' => 'Bonjour', 'author' => 'Steve']);
    translate('en', ['title' => 'Hello'], $entry);

    expect(Entry::query()->inLocale('en')->sole()->author)->toBe('Steve');

    translate('en', ['author' => 'Anna'], $entry);

    expect(Entry::query()->sole()->author)->toBe('Anna')
        ->and(Entry::query()->inLocale('en')->sole()->author)->toBe('Anna');
});

it('falls back to the default Locale for a blank Translatable Field, with its lang', function () {
    $entry = translate('fr', ['title' => 'Bonjour', 'summary' => 'Un article.']);
    translate('en', ['title' => 'Hello'], $entry);

    $english = Entry::query()->inLocale('en')->sole();
    $french = Entry::query()->sole();

    expect([$english->summary, $english->fallbackLocale('summary')])->toBe(['Un article.', 'fr'])
        ->and([$french->summary, $french->fallbackLocale('summary')])->toBe(['Un article.', null])
        ->and($english->fallbackLocale('title'))->toBeNull();

    $this->get('/en/articles/hello')->assertOk()->assertSee('<p lang="fr">Un article.</p>', escape: false);
});

it('never falls back to a Draft or Scheduled default Translation', function (array $french) {
    $entry = translate('fr', ['title' => 'Bonjour', 'summary' => 'Pas encore.', ...$french]);
    translate('en', ['title' => 'Hello'], $entry);

    $english = Entry::query()->inLocale('en')->sole();

    expect([$english->summary, $english->fallbackLocale('summary')])->toBe([null, null]);
})->with([
    'Draft' => [['status' => 'draft']],
    'Scheduled' => [['published_at' => now()->addDay()]],
]);

it('answers an untranslatable Entry in every Locale through its one Translation', function () {
    $label = resolve(SaveEntry::class)->handle(Label::class, ['title' => 'Urgent', 'status' => 'published', 'colour' => 'red']);

    expect(Entry::query()->inLocale('en')->sole()->colour)->toBe('red')
        ->and(Entry::query()->sole()->id)->toBe($label->id);

    $this->get('/label/urgent')->assertOk()->assertSee('<html lang="fr">', escape: false)->assertSee('red');
    $this->get('/en/label/urgent')->assertOk()->assertSee('<html lang="en">', escape: false)->assertSee('red');
});

it('answers a Page at the root, its Textarea kept as written', function () {
    $page = translate('fr', ['title' => 'À propos', 'slug' => 'a-propos', 'body' => "Ligne un\nLigne deux"], type: Page::class);
    translate('en', ['title' => 'About'], $page, type: Page::class);

    expect(Entry::query()->sole()->body)->toBe("Ligne un\nLigne deux");

    $this->get('/a-propos')->assertOk()->assertSee("Ligne un\nLigne deux");
    $this->get('/en/about')->assertOk()->assertSee('<div lang="fr">', escape: false);
});

it('gives the absolute URL of a visible Translation, or null', function () {
    $entry = translate('fr', ['title' => 'Bonjour']);
    translate('en', ['title' => 'Hello', 'status' => 'draft'], $entry);
    $label = resolve(SaveEntry::class)->handle(Label::class, ['title' => 'Urgent', 'status' => 'published']);

    $entry = Entry::query()->whereKey($entry->id)->sole();

    expect($entry->url())->toBe('http://localhost/articles/bonjour')
        ->and($entry->url('fr'))->toBe('http://localhost/articles/bonjour')
        ->and($entry->url('en'))->toBeNull()
        ->and($entry->url('de'))->toBeNull()
        ->and(Entry::query()->whereKey($label->id)->sole()->url('en'))->toBe('http://localhost/en/label/urgent');

    translate('en', ['status' => 'published'], $entry);

    expect(Entry::query()->inLocale('en')->whereKey($entry->id)->sole()->url())->toBe('http://localhost/en/articles/hello');
});

it('falls back without a query of its own', function () {
    $entry = translate('fr', ['title' => 'Bonjour', 'summary' => 'Un article.']);
    translate('en', ['title' => 'Hello'], $entry);

    DB::enableQueryLog();
    $this->get('/en/articles/hello')->assertOk()->assertSee('Un article.');

    // The Path, the Entry with its visibility, its Translations, its type rows: as on a page with no Fallback.
    expect(DB::getQueryLog())->toHaveCount(4);
});
