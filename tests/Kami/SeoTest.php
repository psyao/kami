<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\RichText;
use Psyao\Kami\Content\Internal\PreviewLink;
use Psyao\Kami\Content\RichText\Document;
use Psyao\Kami\Content\SiteSettings;
use Psyao\Kami\Facades\Kami;
use Workbench\App\Content\Article;
use Workbench\App\Content\Home;
use Workbench\App\Content\Page;
use Workbench\App\Content\Project;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    Kami::routes();
    config(['app.name' => 'Kami Site', 'kami.indexable' => true]);
});

/** @param array<string, mixed> $values */
function seoArticle(array $values, string $locale = 'fr', ?Entry $entry = null): Entry
{
    return resolve(SaveEntry::class)->handle(Article::class, ['status' => 'published', 'author' => 'Ada', ...$values], $entry, $locale);
}

/** @param array<string, mixed> $values */
function siteSettings(array $values, string $locale = 'fr'): void
{
    resolve(SaveEntry::class)->handle('site_settings', $values, SiteSettings::entry(), $locale);
}

it('titles a page with its title and the Site name, the Meta title in its place, and the home page with the Site name alone', function () {
    seoArticle(['title' => 'Bonjour']);
    seoArticle(['title' => 'Salut', 'meta_title' => 'Un titre <à part>']);
    resolve(SaveEntry::class)->handle(Home::class, ['title' => 'Accueil', 'status' => 'published'], locale: 'fr');

    $this->get('/articles/bonjour')->assertOk()->assertSee('<title>Bonjour – Kami Site</title>', escape: false);
    $this->get('/articles/salut')->assertOk()->assertSee('<title>Un titre &lt;à part&gt;</title>', escape: false);
    $this->get('/')->assertOk()->assertSee('<title>Kami Site</title>', escape: false);
});

it('names the Site from its settings, a blank name read in the default Locale, then as the application\'s name', function () {
    $entry = seoArticle(['title' => 'Bonjour']);
    seoArticle(['title' => 'Hello'], 'en', $entry);

    $this->get('/en/articles/hello')->assertSee('<title>Hello – Kami Site</title>', escape: false);

    siteSettings(['site_name' => 'Mon site']);
    $this->get('/en/articles/hello')->assertSee('<title>Hello – Mon site</title>', escape: false);

    siteSettings(['site_name' => 'My site'], 'en');
    $this->get('/en/articles/hello')->assertSee('<title>Hello – My site</title>', escape: false);
    $this->get('/articles/bonjour')->assertSee('<title>Bonjour – Mon site</title>', escape: false);
});

it('describes a page with its Meta description, else its summary, in its own Locale only', function () {
    $entry = seoArticle(['title' => 'Bonjour', 'body' => Document::fromHtml('<p>Un <strong>premier</strong> article, ' . str_repeat('assez long ', 20) . '.</p>', RichText::make('body'))]);
    seoArticle(['title' => 'Hello'], 'en', $entry);
    seoArticle(['title' => 'Salut', 'body' => Document::fromHtml('<p>Ignoré.</p>', RichText::make('body')), 'meta_description' => 'Une description "à part".']);

    $this->get('/articles/bonjour')->assertSee('<meta name="description" content="Un premier article, assez long assez long assez long assez long assez long assez long assez long assez long assez long assez long assez long assez long assez…">', escape: false);
    $this->get('/articles/salut')->assertSee('<meta name="description" content="Une description &quot;à part&quot;.">', escape: false);
    // The French body falls back into the English page's text, never into its description.
    $this->get('/en/articles/hello')->assertSee('premier')->assertDontSee('name="description"', escape: false);
});

it('reads a Field named summary as an attribute and the type\'s summary as a call', function () {
    $entry = seoArticle(['title' => 'Bonjour', 'summary' => 'Le champ.', 'body' => Document::fromHtml('<p>Le corps.</p>', RichText::make('body'))]);

    expect($entry->summary)->toBe('Le champ.')
        ->and($entry->summary())->toBe('Le corps.')
        ->and(seoArticle(['title' => 'Vide'])->summary())->toBeNull();
});

it('reads summary on a type with no Field of that name as any missing attribute', function () {
    $page = resolve(SaveEntry::class)->handle(Page::class, ['title' => 'À propos'], locale: 'fr');

    expect(fn () => $page->summary)->toThrow(MissingAttributeException::class);

    Model::preventAccessingMissingAttributes(false);

    expect($page->summary)->toBeNull();
});

it('describes a page with what its type\'s summary() returns', function () {
    resolve(SaveEntry::class)->handle(Home::class, ['title' => 'Accueil', 'intro' => 'Bienvenue.', 'status' => 'published'], locale: 'fr');
    siteSettings(['default_description' => 'Le site de Kami.']);

    $this->get('/')->assertSee('<meta name="description" content="Bienvenue.">', escape: false);
});

it('describes the home page alone with the Site\'s default description, in its own Locale only', function () {
    resolve(SaveEntry::class)->handle(Home::class, ['title' => 'Accueil', 'status' => 'published'], locale: 'fr');
    resolve(SaveEntry::class)->handle(Home::class, ['title' => 'Home', 'status' => 'published'], Entry::query()->ofType(Home::class)->sole(), 'en');
    seoArticle(['title' => 'Bonjour']);
    siteSettings(['default_description' => 'Le site de Kami.']);

    $this->get('/')->assertSee('<meta name="description" content="Le site de Kami.">', escape: false);
    $this->get('/articles/bonjour')->assertDontSee('name="description"', escape: false);
    $this->get('/en')->assertOk()->assertDontSee('name="description"', escape: false);
});

it('points the canonical and og:url at the declared Site URL, whatever the request\'s host and query string', function () {
    $entry = seoArticle(['title' => 'Bonjour']);
    seoArticle(['title' => 'Hello'], 'en', $entry);
    config(['kami.sites.default.url' => 'https://example.com']);

    $this->get('http://other.localhost/articles/bonjour?utm_source=newsletter&x=<y>')->assertOk()->assertSeeInOrder([
        '<link rel="canonical" href="https://example.com/articles/bonjour">',
        '<meta property="og:url" content="https://example.com/articles/bonjour">',
    ], escape: false);
    $this->get('/en/articles/hello')->assertSee('<link rel="canonical" href="https://example.com/en/articles/hello">', escape: false);
});

it('keeps a page out of search engines on a Site that is not indexable, or when its Translation is hidden', function () {
    $entry = seoArticle(['title' => 'Bonjour', 'noindex' => true]);
    seoArticle(['title' => 'Hello'], 'en', $entry);

    $this->get('/articles/bonjour')->assertSee('<meta name="robots" content="noindex">', escape: false);
    $this->get('/en/articles/hello')->assertDontSee('name="robots"', escape: false);

    config(['kami.indexable' => false]);

    $this->get('/articles/bonjour')->assertSee('<meta name="robots" content="noindex, nofollow">', escape: false);
    $this->get('/en/articles/hello')->assertSee('<meta name="robots" content="noindex, nofollow">', escape: false);
});

it('heads a Preview with noindex and the saved URL as canonical, none for a Draft', function () {
    $user = User::forceCreate(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'secret']);
    $published = seoArticle(['title' => 'Bonjour']);
    $draft = seoArticle(['title' => 'Brouillon', 'status' => 'draft']);

    $this->get(PreviewLink::issue($published, 'fr', 7, $user))->assertOk()
        ->assertSee('<title>Bonjour – Kami Site</title>', escape: false)
        ->assertSee('<link rel="canonical" href="http://localhost/articles/bonjour">', escape: false)
        ->assertSee('<meta name="robots" content="noindex">', escape: false);
    $this->get(PreviewLink::issue($draft, 'fr', 7, $user))->assertOk()
        ->assertSee('<title>Brouillon – Kami Site</title>', escape: false)
        ->assertDontSee('rel="canonical"', escape: false)
        ->assertSee('<meta name="robots" content="noindex">', escape: false);
});

it('takes a title and a description on a page Kami does not render, and ignores them on its own', function () {
    $entry = seoArticle(['title' => 'Bonjour']);
    Route::get('contact', fn () => Blade::render('<x-kami::seo title="Contact <us>" description="Écrivez-nous." />'));

    expect($this->get('/contact')->assertOk()->getContent())->toBe(implode("\n", [
        '<title>Contact &lt;us&gt; – Kami Site</title>',
        '<meta name="description" content="Écrivez-nous.">',
        '',
    ]));
    expect(Kami::seo())->title->toBe('Kami Site')->description->toBeNull()->canonical->toBeNull()->robots->toBeNull()->siteName->toBe('Kami Site');

    $this->get('/articles/bonjour');
    expect(Blade::render('<x-kami::seo title="Ignored" />'))->toContain('<title>Bonjour – Kami Site</title>')
        ->and(Kami::seo()->canonical)->toBe($entry->url());
});

it('adds no query to a page, and none with the number of its related Entries', function () {
    $related = collect(range(1, 50))->map(fn (int $n): string => seoArticle(['title' => "Article {$n}"])->id);
    resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Un', 'status' => 'published', 'related' => [$related->first()]]);
    resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Cinquante', 'status' => 'published', 'related' => $related->all()]);
    $queries = function (string $path): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($path)->assertOk();

        return count(DB::getQueryLog());
    };

    $withHead = $queries('/project/un');
    expect($queries('/project/cinquante'))->toBe($withHead);

    $views = temporaryPath();
    File::ensureDirectoryExists("{$views}/kami");
    File::put("{$views}/kami/project.blade.php", str_replace('<x-kami::seo />', '<title>Un</title>', File::get(__DIR__ . '/../../workbench/resources/views/kami/project.blade.php')));
    View::getFinder()->prependLocation($views);
    View::getFinder()->flush();

    try {
        expect($queries('/project/un'))->toBe($withHead);
        $this->get('/project/un')->assertSee('<title>Un</title>', escape: false);
    } finally {
        File::deleteDirectory($views);
    }
});
