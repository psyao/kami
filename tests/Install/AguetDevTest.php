<?php

declare(strict_types=1);

namespace Psyao\Kami\Tests\Install\AguetDev;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Boolean;
use Psyao\Kami\Content\Fields\Email;
use Psyao\Kami\Content\Fields\Terms;
use Psyao\Kami\Content\Fields\Text;
use Psyao\Kami\Content\Fields\Textarea;
use Psyao\Kami\Content\Fields\Url;
use Psyao\Kami\Content\Singleton;
use Psyao\Kami\Content\SiteSettings;
use Psyao\Kami\Content\Taxonomy;
use Psyao\Kami\Facades\Kami;

// M3's acceptance: aguet.dev's types as docs/research/site-aguet-dev.md describes them. `about_body` stays a Textarea until M4's rich text; `url` becomes `website`, as `Entry::url()` holds the name.

final class Home extends Singleton
{
    #[\Override]
    protected ?string $prefix = '';

    public function fields(): array
    {
        return [
            Textarea::make('hero_title'),
            Text::make('hero_subtitle'),
            Text::make('hero_role'),
            Text::make('hero_location'),
            Text::make('hero_exp'),
            Text::make('hero_focus'),
            Textarea::make('about_body'),
            Text::make('contact_lead'),
            Email::make('contact_email')->shared(),
            Url::make('contact_linkedin')->shared(),
            Text::make('contact_linkedin_label')->shared(),
            Url::make('contact_github')->shared(),
            Text::make('contact_github_label')->shared(),
        ];
    }
}

final class Project extends ContentType
{
    #[\Override]
    protected bool $routable = false;

    #[\Override]
    protected bool $orderable = true;

    public function fields(): array
    {
        return [
            Text::make('client'),
            Text::make('role'),
            Textarea::make('summary'),
            Url::make('website')->shared(),
            Boolean::make('featured')->shared(),
            Terms::make('tags')->of(Tag::class),
        ];
    }
}

final class SkillGroup extends ContentType
{
    #[\Override]
    protected bool $routable = false;

    #[\Override]
    protected bool $orderable = true;

    public function fields(): array
    {
        return [
            Text::make('text'),
            Text::make('note'),
            Boolean::make('focus')->shared(),
            Terms::make('tags')->of(Tag::class),
        ];
    }
}

final class Tag extends Taxonomy
{
    #[\Override]
    protected bool $translatable = false;

    #[\Override]
    protected bool $routable = false;
}

/**
 * Installs aguet.dev the way its deploy does: `kami:install`, a migration per type, `kami:install` again.
 * Its home view prints the head through `<x-kami::seo />` and lists the Projects with their Tags.
 */
function installAguetDev(bool $indexable = false): void
{
    $test = test();
    $test->bootWithKamiOnAPanel();
    config([
        'app.url' => 'https://aguet.dev',
        'kami.sites.default.url' => 'https://aguet.dev',
        'kami.indexable' => $indexable,
        'kami.sites.default.locales' => ['fr' => ['region' => 'CH'], 'en' => ['region' => 'GB']],
    ]);
    $site = temporaryPath();
    $test->beforeApplicationDestroyed(fn () => File::deleteDirectory($site));
    app()->useDatabasePath("{$site}/database");
    File::ensureDirectoryExists(database_path('migrations'));
    File::ensureDirectoryExists("{$site}/views/kami");
    File::put("{$site}/views/kami/home.blade.php", <<<'BLADE'
        <!DOCTYPE html>
        <html lang="{{ app()->getLocale() }}">
        <head>
            <x-kami::seo />
        </head>
        <body>
        <h1>{{ $entry->hero_title }}</h1>
        @foreach (\Psyao\Kami\Content\Entry::query()->ofType(\Psyao\Kami\Tests\Install\AguetDev\Project::class)->ordered()->with('tags')->get() as $project)
            <h2>{{ $project->title }}</h2>
            @foreach ($project->tags as $tag)<span>{{ $tag->title }}</span>@endforeach
        @endforeach
        </body>
        </html>
        BLADE);
    View::addLocation("{$site}/views");
    $install = ['kami:install', ['--email' => 'admin@example.test', '--name' => 'Admin', '--no-interaction' => true]];

    $test->artisan(...$install)->assertSuccessful();
    config(['kami.types' => [Home::class, Project::class, SkillGroup::class, Tag::class]]);

    foreach (['home', 'project', 'skill_group'] as $type) {
        $test->artisan('kami:make:migration', ['type' => $type, '--no-interaction' => true])->assertSuccessful();
        // It refuses to work from a schema that its own pending migrations are about to change.
        $test->artisan('migrate')->assertSuccessful();
    }

    $test->artisan(...$install)->assertSuccessful();
    Kami::routes();
}

it('declares aguet.dev, and its Home lists Projects by hand with Tags in pick order', function (): void {
    installAguetDev();
    $save = resolve(SaveEntry::class);
    $save->handle(Home::class, ['title' => 'Accueil', 'status' => 'published', 'hero_title' => 'Bonjour'], Entry::query()->withUnpublished()->ofType(Home::class)->sole(), 'fr');
    [$php, $laravel, $vue] = array_map(fn (string $title) => $save->handle(Tag::class, ['title' => $title, 'status' => 'published']), ['PHP', 'Laravel', 'Vue']);
    $save->handle(SkillGroup::class, ['title' => 'Backend', 'status' => 'published', 'tags' => [$php->id]], locale: 'fr');
    $save->handle(Project::class, ['title' => 'CVCI', 'status' => 'published', 'tags' => [$vue->id, $php->id, $laravel->id]], locale: 'fr');
    $save->handle(Project::class, ['title' => 'Animalia', 'status' => 'published', 'tags' => [$laravel->id]], locale: 'fr');

    $this->get('/')->assertOk()->assertSeeInOrder(['Bonjour', 'CVCI', 'Vue', 'PHP', 'Laravel', 'Animalia', 'Laravel']);
    $this->get('/project/cvci')->assertNotFound();
    expect(Entry::query()->ofType(SkillGroup::class)->sole()->tags->pluck('title')->all())->toBe(['PHP']);
});

// M7's acceptance: aguet.dev's HomePageTest head assertions, tag by tag, each present once. No Social image is set, as aguet.dev has none.
it('carries the Home\'s SEO in its head in each language', function (): void {
    installAguetDev(indexable: true);
    $save = resolve(SaveEntry::class);
    $save->handle('site_settings', ['site_name' => 'aguet.dev'], SiteSettings::entry(), 'fr');
    $home = Entry::query()->withUnpublished()->ofType(Home::class)->sole();
    $save->handle(Home::class, [
        'title' => 'Accueil',
        'status' => 'published',
        'meta_title' => 'Steve Aguet — Développeur web full-stack Laravel',
        'meta_description' => 'Steve Aguet, développeur web full-stack à dominante back-end, spécialisé Laravel — région lémanique. Intégration & automatisation, plateformes sur mesure.',
    ], $home, 'fr');
    $save->handle(Home::class, [
        'title' => 'Home',
        'status' => 'published',
        'meta_title' => 'Steve Aguet — Full-stack Laravel web developer',
        'meta_description' => 'Steve Aguet, full-stack web developer with a back-end focus, Laravel specialist — Lake Geneva region. Integration & automation, custom platforms.',
    ], $home->refresh(), 'en');
    $heads = [
        '/' => [
            'title' => 'Steve Aguet — Développeur web full-stack Laravel',
            'description' => 'Steve Aguet, développeur web full-stack à dominante back-end, spécialisé Laravel — région lémanique. Intégration &amp; automatisation, plateformes sur mesure.',
            'url' => 'https://aguet.dev',
            'locale' => 'fr_CH',
            'alternate' => 'en_GB',
        ],
        '/en' => [
            'title' => 'Steve Aguet — Full-stack Laravel web developer',
            'description' => 'Steve Aguet, full-stack web developer with a back-end focus, Laravel specialist — Lake Geneva region. Integration &amp; automation, custom platforms.',
            'url' => 'https://aguet.dev/en',
            'locale' => 'en_GB',
            'alternate' => 'fr_CH',
        ],
    ];

    foreach ($heads as $path => $head) {
        $html = $this->get($path)->assertOk()->getContent();

        foreach ([
            '<title>' . $head['title'] . '</title>',
            '<meta name="description" content="' . $head['description'] . '">',
            '<link rel="canonical" href="' . $head['url'] . '">',
            '<link rel="alternate" hreflang="fr" href="https://aguet.dev">',
            '<link rel="alternate" hreflang="en" href="https://aguet.dev/en">',
            '<link rel="alternate" hreflang="x-default" href="https://aguet.dev">',
            '<meta property="og:type" content="website">',
            '<meta property="og:site_name" content="aguet.dev">',
            '<meta property="og:title" content="' . $head['title'] . '">',
            '<meta property="og:description" content="' . $head['description'] . '">',
            '<meta property="og:url" content="' . $head['url'] . '">',
            '<meta property="og:locale" content="' . $head['locale'] . '">',
            '<meta property="og:locale:alternate" content="' . $head['alternate'] . '">',
            '<meta name="twitter:card" content="summary">',
        ] as $tag) {
            expect(substr_count($html, $tag))->toBe(1, "{$path}: {$tag}");
        }

        expect(substr_count($html, '<title>'))->toBe(1, "{$path}: one title")
            ->and($html)->not->toContain('og:image');
    }
});

// aguet.dev's robots expectations, its environment switch now `kami.indexable`. Its sitemap expectation comes with #410.
it('keeps the admin out of robots.txt when indexable, and everything out otherwise', function (bool $indexable, string $rules, ?string $header): void {
    installAguetDev($indexable);
    resolve(SaveEntry::class)->handle(Home::class, ['title' => 'Accueil', 'status' => 'published'], Entry::query()->withUnpublished()->ofType(Home::class)->sole(), 'fr');

    $response = $this->get('/');

    expect(File::get(public_path('robots.txt')))->toBe("# Managed by Kami (kami:install). Delete this line to own this file.\nUser-agent: *\n{$rules}");
    expect($response->assertOk()->headers->get('X-Robots-Tag'))->toBe($header);
})->with([
    'indexable' => [true, "Disallow: /admin\n\nSitemap: https://aguet.dev/sitemap.xml\n", null],
    'not indexable' => [false, "Disallow: /\n", 'noindex, nofollow'],
]);
