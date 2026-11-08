<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\RichText;
use Psyao\Kami\Content\Internal\PreviewLink;
use Psyao\Kami\Content\RichText\Document;
use Psyao\Kami\Content\SiteSettings;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Media\Actions\DeleteMedia;
use Psyao\Kami\Media\Actions\SaveMedia;
use Psyao\Kami\Media\Media;
use Workbench\App\Content\Article;
use Workbench\App\Content\Home;
use Workbench\App\Content\Page;
use Workbench\App\Content\Project;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    Kami::routes();
    config(['app.name' => 'Kami Site', 'kami.indexable' => true]);
    Storage::fake('local');
    Storage::fake('public');
});

/** @param array<string, mixed> $values */
function socialPhoto(array $values = [], string $fixture = 'rotated-gps.jpg'): Media
{
    return resolve(SaveMedia::class)->handle(fixture("media/{$fixture}"), $values);
}

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

it('links a page to its visible Translations not Hidden from search engines, in both directions, with x-default', function () {
    config(['kami.sites.default.locales' => ['fr', 'en', 'de']]);
    $entry = seoArticle(['title' => 'Bonjour']);
    seoArticle(['title' => 'Hello'], 'en', $entry);
    seoArticle(['title' => 'Hallo', 'noindex' => true], 'de', $entry);
    $links = [
        '<link rel="alternate" hreflang="fr" href="http://localhost/articles/bonjour">',
        '<link rel="alternate" hreflang="en" href="http://localhost/en/articles/hello">',
        '<link rel="alternate" hreflang="x-default" href="http://localhost/articles/bonjour">',
    ];

    $this->get('/articles/bonjour')->assertSeeInOrder($links, escape: false)->assertDontSee('rel="alternate" hreflang="de"', escape: false);
    $this->get('/en/articles/hello')->assertSeeInOrder($links, escape: false)->assertDontSee('rel="alternate" hreflang="de"', escape: false);
    // A hidden page leaves hreflang in both directions.
    $this->get('/de/articles/hallo')->assertOk()->assertDontSee('rel="alternate"', escape: false);
    // A page that is not indexable as a whole keeps them.
    config(['kami.indexable' => false]);
    $this->get('/articles/bonjour')->assertSeeInOrder($links, escape: false);
});

it('links no x-default when the default Locale\'s Translation is hidden or not visible, and nothing below two links', function () {
    config(['kami.sites.default.locales' => ['fr', 'en', 'de']]);
    $entry = seoArticle(['title' => 'Bonjour', 'status' => 'draft']);
    seoArticle(['title' => 'Hello'], 'en', $entry);
    seoArticle(['title' => 'Hallo'], 'de', $entry);

    $this->get('/en/articles/hello')->assertSeeInOrder([
        '<link rel="alternate" hreflang="en" href="http://localhost/en/articles/hello">',
        '<link rel="alternate" hreflang="de" href="http://localhost/de/articles/hallo">',
    ], escape: false)->assertDontSee('x-default', escape: false)->assertDontSee('rel="alternate" hreflang="fr"', escape: false);

    seoArticle(['title' => 'Hallo', 'noindex' => true], 'de', $entry);
    $this->get('/en/articles/hello')->assertDontSee('rel="alternate"', escape: false);
    expect(Kami::seo()->alternates)->toBe([]);
});

it('links no Translation on a monolingual Site, and the Paths of an untranslatable Entry to each other', function () {
    resolve(SaveEntry::class)->handle('label', ['title' => 'Urgent', 'status' => 'published']);

    $this->get('/en/label/urgent')->assertOk()->assertSeeInOrder([
        '<link rel="alternate" hreflang="fr" href="http://localhost/label/urgent">',
        '<link rel="alternate" hreflang="en" href="http://localhost/en/label/urgent">',
        '<link rel="alternate" hreflang="x-default" href="http://localhost/label/urgent">',
    ], escape: false);

    config(['kami.sites.default.locales' => ['fr']]);
    $this->get('/label/urgent')->assertOk()->assertDontSee('rel="alternate"', escape: false);
});

it('leaves hreflang out of a Preview', function () {
    $user = User::forceCreate(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'secret']);
    $entry = seoArticle(['title' => 'Bonjour']);
    seoArticle(['title' => 'Hello'], 'en', $entry);

    $this->get(PreviewLink::issue($entry, 'fr', 7, $user))->assertOk()->assertDontSee('rel="alternate"', escape: false);
});

it('gives og:locale and its alternates from a Locale\'s region, in its options or its code, and nothing without one', function () {
    config(['kami.sites.default.locales' => ['fr' => ['region' => 'ch'], 'en-gb', 'de']]);
    $entry = seoArticle(['title' => 'Bonjour']);
    seoArticle(['title' => 'Hello'], 'en-gb', $entry);
    seoArticle(['title' => 'Hallo'], 'de', $entry);
    Route::get('contact', fn () => Blade::render('<x-kami::seo title="Contact" />'));

    $this->get('/contact')->assertSee('<meta property="og:locale" content="fr_CH">', escape: false)->assertDontSee('og:locale:alternate', escape: false);
    $this->get('/articles/bonjour')->assertSeeInOrder([
        '<link rel="alternate" hreflang="en-gb" href="http://localhost/en-gb/articles/hello">',
        '<meta property="og:type" content="article">',
        '<meta property="og:locale" content="fr_CH">',
        '<meta property="og:locale:alternate" content="en_GB">',
        '<meta name="twitter:card"',
    ], escape: false)->assertDontSee('content="de', escape: false);
    $this->get('/de/articles/hallo')->assertDontSee('property="og:locale"', escape: false)->assertSeeInOrder([
        '<meta property="og:locale:alternate" content="fr_CH">',
        '<meta property="og:locale:alternate" content="en_GB">',
    ], escape: false);
    expect(Kami::seo())->ogLocale->toBeNull()->ogLocaleAlternates->toBe(['fr_CH', 'en_GB']);
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
        '<meta property="og:title" content="Contact &lt;us&gt; – Kami Site">',
        '<meta property="og:description" content="Écrivez-nous.">',
        '<meta property="og:site_name" content="Kami Site">',
        '<meta property="og:type" content="website">',
        '<meta name="twitter:card" content="summary">',
        '',
    ]));
    expect(Kami::seo())->title->toBe('Kami Site')->description->toBeNull()->canonical->toBeNull()->robots->toBeNull()->siteName->toBe('Kami Site');

    $this->get('/articles/bonjour');
    expect(Blade::render('<x-kami::seo title="Ignored" />'))->toContain('<title>Bonjour – Kami Site</title>')
        ->and(Kami::seo()->canonical)->toBe($entry->url());
});

it('adds no query to a page, and none with the number of its related Entries', function () {
    $related = collect(range(1, 50))->map(fn (int $n): string => seoArticle(['title' => "Article {$n}"])->id);
    // Its Social image read through its type's socialImage(), past an empty Reference of its own.
    $cover = socialPhoto()->id;
    resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Un', 'status' => 'published', 'cover' => $cover, 'related' => [$related->first()]]);
    resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Cinquante', 'status' => 'published', 'cover' => $cover, 'related' => $related->all()]);
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

it('cards a page with its Social image, shared by its Translations, as the kami-social Conversion with its size and alt text', function () {
    // Shown 40 × 60: cut to 40 × 21 at 40:21, never upscaled.
    $media = socialPhoto(['alt' => 'Un drapeau <rouge>']);
    resolve(SaveMedia::class)->handle(null, ['alt' => 'A red flag'], $media, 'en');
    $entry = seoArticle(['title' => 'Bonjour', 'seo_image' => $media->id]);
    seoArticle(['title' => 'Hello'], 'en', $entry);

    $this->get('/articles/bonjour')->assertOk()->assertSeeInOrder([
        '<meta property="og:title" content="Bonjour – Kami Site">',
        '<meta property="og:site_name" content="Kami Site">',
        '<meta property="og:type" content="article">',
        '<meta property="og:image" content="' . $media->url('kami-social') . '">',
        '<meta property="og:image:width" content="40">',
        '<meta property="og:image:height" content="21">',
        '<meta property="og:image:alt" content="Un drapeau &lt;rouge&gt;">',
        '<meta name="twitter:card" content="summary_large_image">',
    ], escape: false);
    $this->get('/en/articles/hello')->assertSee('<meta property="og:image:alt" content="A red flag">', escape: false);
    expect($media->url('kami-social'))->toEndWith('/kami-social-1200.jpg');
});

it('falls to the type\'s socialImage(), then to the Site\'s default Social image, skipping an SVG', function () {
    $own = socialPhoto(['alt' => 'Propre']);
    $cover = socialPhoto(['alt' => 'Couverture']);
    $default = socialPhoto(['alt' => 'Par défaut'], 'pixel.png');
    $svg = socialPhoto([], 'logo.svg');
    resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Propre', 'status' => 'published', 'cover' => $cover->id, 'seo_image' => $own->id], locale: 'fr');
    resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Couverte', 'status' => 'published', 'cover' => $cover->id, 'seo_image' => $svg->id], locale: 'fr');
    resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Nue', 'status' => 'published'], locale: 'fr');

    $this->get('/project/propre')->assertSee('<meta property="og:image:alt" content="Propre">', escape: false);
    $this->get('/project/couverte')->assertSee('<meta property="og:image:alt" content="Couverture">', escape: false);
    $this->get('/project/nue')->assertDontSee('og:image', escape: false)->assertSee('<meta name="twitter:card" content="summary">', escape: false);

    siteSettings(['default_social_image' => $default->id]);

    $this->get('/project/nue')->assertSee('<meta property="og:image" content="' . $default->url('kami-social') . '">', escape: false)
        ->assertSee('<meta property="og:image:alt" content="Par défaut">', escape: false);
    expect($default->url('kami-social'))->toEndWith('/kami-social-1200.png');

    siteSettings(['default_social_image' => $svg->id]);

    $this->get('/project/nue')->assertDontSee('og:image', escape: false);
});

it('leaves out the alt text of a decorative image, and of one that has none', function () {
    $entry = seoArticle(['title' => 'Bonjour', 'seo_image' => socialPhoto(['alt' => 'Ignoré', 'decorative' => true])->id]);
    seoArticle(['title' => 'Salut', 'seo_image' => socialPhoto()->id]);

    $this->get('/articles/bonjour')->assertSee('og:image:width', escape: false)->assertDontSee('og:image:alt', escape: false);
    $this->get('/articles/salut')->assertSee('og:image:width', escape: false)->assertDontSee('og:image:alt', escape: false);
});

it('dates an article with its publication and its last save, a website with neither', function () {
    $this->freezeSecond();
    seoArticle(['title' => 'Bonjour', 'published_at' => '2026-01-02 03:04:05']);
    resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Un', 'status' => 'published']);

    $this->get('/articles/bonjour')->assertSeeInOrder([
        '<meta property="og:type" content="article">',
        '<meta property="article:published_time" content="2026-01-02T03:04:05+00:00">',
        '<meta property="article:modified_time" content="' . now()->toIso8601String() . '">',
    ], escape: false);
    $this->get('/project/un')->assertSee('<meta property="og:type" content="website">', escape: false)->assertDontSee('article:', escape: false);
});

it('stores the Social image as a Reference shared by every Locale, which keeps its Media item from deletion', function () {
    $media = socialPhoto();
    $entry = seoArticle(['title' => 'Bonjour']);
    seoArticle(['title' => 'Hello', 'seo_image' => $media->id], 'en', $entry);

    expect(DB::table('kami_references')->where('source_entry_id', $entry->id)->get(['field', 'locale', 'target_media_id'])->map(fn (object $row): array => (array) $row)->all())
        ->toBe([['field' => 'seo_image', 'locale' => null, 'target_media_id' => $media->id]])
        ->and(fn () => resolve(DeleteMedia::class)->handle($media))->toThrow(ValidationException::class);

    seoArticle(['seo_image' => null], 'fr', $entry);
    resolve(DeleteMedia::class)->handle($media);

    expect(Media::query()->count())->toBe(0);
});

it('refuses a Social image that is a document, and one on a type with no URL', function () {
    $document = socialPhoto([], 'brochure.pdf');

    expect(fn () => seoArticle(['title' => 'Bonjour', 'seo_image' => $document->id]))->toThrow(ValidationException::class, 'social image')
        ->and(fn () => siteSettings(['seo_image' => socialPhoto()->id]))->toThrow(ValidationException::class, 'prohibited');
});

it('cards a page Kami does not render with the Site\'s default Social image', function () {
    $default = socialPhoto(['alt' => 'Par défaut']);
    Route::get('contact', fn () => Blade::render('<x-kami::seo title="Contact" />'));

    $this->get('/contact')->assertSee('<meta property="og:type" content="website">', escape: false)
        ->assertDontSee('og:image', escape: false)->assertSee('<meta name="twitter:card" content="summary">', escape: false);

    siteSettings(['default_social_image' => $default->id]);

    $this->get('/contact')->assertSee('<meta property="og:image" content="' . $default->url('kami-social') . '">', escape: false)
        ->assertSee('<meta name="twitter:card" content="summary_large_image">', escape: false);
});

it('cards a Preview with the Social image', function () {
    $media = socialPhoto();
    $entry = seoArticle(['title' => 'Bonjour', 'seo_image' => $media->id]);

    $this->get(PreviewLink::issue($entry, 'fr', 7, User::forceCreate(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'secret'])))->assertOk()
        ->assertSee('<meta property="og:image" content="' . $media->url('kami-social') . '">', escape: false);
});
