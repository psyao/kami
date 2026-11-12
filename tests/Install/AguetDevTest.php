<?php

declare(strict_types=1);

namespace Psyao\Kami\Tests\Install\AguetDev;

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Psyao\Kami\Audit\Events\AuditEventRecorded;
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
use Psyao\Kami\Forms\Form;
use Psyao\Kami\Forms\Submission;
use Psyao\Kami\Import\Import;
use Psyao\Kami\Import\ImportReport;
use Psyao\Kami\Media\Media;

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

/** aguet.dev's contact form, its Livewire component's rules as a Form. */
final class ContactForm extends Form
{
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ];
    }

    public function recipients(): array
    {
        return ['contact@aguet.dev'];
    }

    public function replyTo(): string
    {
        return 'email';
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

// aguet.dev's robots expectations, its environment switch now `kami.indexable`.
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

// aguet.dev's sitemap expectation: the index now over one file per Locale, whose URLs it reads in turn.
it('lists both homes in the sitemap, with their alternates, and nothing else', function (): void {
    installAguetDev(indexable: true);
    $save = resolve(SaveEntry::class);
    $home = Entry::query()->withUnpublished()->ofType(Home::class)->sole();
    $save->handle(Home::class, ['title' => 'Accueil', 'status' => 'published'], $home, 'fr');
    $save->handle(Home::class, ['title' => 'Home', 'status' => 'published'], $home->refresh(), 'en');
    // In a later second than the saves: a build in the same one is stale already.
    $this->travel(1)->minute();
    $this->artisan('kami:sitemap')->assertSuccessful();

    $index = simplexml_load_string($this->get('/sitemap.xml')->assertOk()->streamedContent());
    $index->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
    $urls = [];

    foreach ($index->xpath('//s:sitemap/s:loc') as $file) {
        $xml = simplexml_load_string($this->get((string) parse_url((string) $file, PHP_URL_PATH))->assertOk()->streamedContent());
        $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        foreach ($xml->xpath('//s:url') as $url) {
            $url->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
            $url->registerXPathNamespace('x', 'http://www.w3.org/1999/xhtml');
            $alternates = [];

            foreach ($url->xpath('x:link') as $link) {
                $alternates[(string) $link['hreflang']] = (string) $link['href'];
            }

            ksort($alternates);
            $urls[(string) $url->xpath('s:loc')[0]] = $alternates;
        }
    }

    $alternates = ['en' => 'https://aguet.dev/en', 'fr' => 'https://aguet.dev', 'x-default' => 'https://aguet.dev'];
    expect($urls)->toBe(['https://aguet.dev' => $alternates, 'https://aguet.dev/en' => $alternates]);
});

// M8's acceptance: aguet.dev's contact message, posted while its mail server is unreachable, stored, then delivered through SMTP by a Tick alone.
it('stores aguet.dev\'s contact message while its SMTP server is down, then delivers it from a Tick only', function (): void {
    installAguetDev();
    // Nothing listens on port 1: the delivery after the response fails, as with a mail server down.
    config(['kami.forms' => [ContactForm::class], 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);
    Kami::schedule();
    // Before the Home is published: travelling back past its publication would hide it.
    $this->travelTo('2026-11-10 10:01:00');
    $home = resolve(SaveEntry::class)->handle(Home::class, ['title' => 'Accueil', 'status' => 'published'], Entry::query()->withUnpublished()->ofType(Home::class)->sole(), 'fr');
    $this->artisan('kami:tick')->assertSuccessful();

    $this->withHeaders(['Sec-Fetch-Site' => 'same-origin'])->post('/kami/forms/contact', [
        '_kami_origin' => "{$home->id}:fr",
        'subject' => 'Une plateforme sur mesure',
        'email' => 'visitor@example.com',
        'message' => 'Bonjour, parlons de mon projet.',
    ])->assertRedirect('https://aguet.dev?sent=contact#contact');

    expect(Submission::query()->sole()->data)->toBe(['subject' => 'Une plateforme sur mesure', 'email' => 'visitor@example.com', 'message' => 'Bonjour, parlons de mon projet.']);
    $delivery = DB::table('kami_form_deliveries')->sole();
    expect([$delivery->channel, $delivery->attempts, $delivery->delivered_at])->toBe(['mail', 1, null])->and($delivery->last_error)->toContain('Connection could not be established');

    config(['mail.default' => 'array']);
    $this->travelTo('2026-11-10 10:16:00');
    $this->artisan('kami:tick')->assertSuccessful();

    $mail = collect(Mail::mailer('array')->getSymfonyTransport()->messages())->sole()->getOriginalMessage();
    expect([$mail->getSubject(), $mail->getTo()[0]->getAddress(), $mail->getReplyTo()[0]->getAddress()])->toBe(['[Contact] Une plateforme sur mesure', 'contact@aguet.dev', 'visitor@example.com'])
        ->and(DB::table('kami_form_deliveries')->sole()->delivered_at)->toBe('2026-11-10 10:16:00');
});

/**
 * `app:import:velox` in miniature, over a synthetic Velox-shaped source: Terms, then Media, then Entries in two Locales, whose Tags resolve through `find()` and whose pass runs twice, then the contact messages.
 */
function importVelox(): ImportReport
{
    $import = Import::start('Velox');
    $date = fn (string $date): CarbonImmutable => CarbonImmutable::parse($date, 'UTC');

    foreach (['01JTAG0PHP' => 'PHP', '01JTAG0LARAVEL' => 'Laravel', '01JTAG0VUE' => 'Vue'] as $ulid => $title) {
        $import->entry('velox.term', $ulid, Tag::class, ['fr' => ['title' => $title, 'status' => 'published']], $date('2025-01-10 09:00:00'));
    }

    $import->media('velox.media', '01JMEDIA0SOCIAL', fixture('media/pixel.png'), ['fr' => ['alt' => 'Steve Aguet'], 'en' => ['alt' => 'Steve Aguet']], $date('2025-01-11 09:00:00'));

    // Twice, as an importer runs its second pass: a Tag found now is handed over now, so a replay hands over what is stored.
    foreach ([1, 2] as $pass) {
        foreach (['01JPROJECT0CVCI' => ['CVCI', ['01JTAG0VUE', '01JTAG0PHP', '01JTAG0LARAVEL']], '01JPROJECT0ANIMALIA' => ['Animalia', ['01JTAG0LARAVEL']]] as $ulid => [$title, $tags]) {
            $import->entry('velox.entry', $ulid, Project::class, [
                'fr' => ['title' => $title, 'status' => 'published', 'summary' => "{$title}, en bref.", 'website' => "https://{$title}.example", 'tags' => array_values(array_filter(array_map(fn (string $tag): ?string => $import->find('velox.term', $tag)?->id, $tags)))],
                'en' => ['title' => "{$title} (en)", 'status' => 'published', 'summary' => "{$title}, in short."],
            ], $date('2025-02-01 09:00:00'), $date('2025-03-01 09:00:00'));
        }
    }

    $import->submission('contact', ['subject' => 'Une plateforme', 'email' => 'visitor@example.com', 'message' => 'Bonjour.'], 'fr', $date('2025-04-01 08:00:00'), $date('2025-04-01 09:00:00'));
    $import->submission('contact', ['subject' => 'A platform', 'email' => 'visitor@example.org', 'message' => 'Hello.'], 'en', $date('2025-04-02 08:00:00'));

    return $import->finish();
}

// M9's acceptance: aguet.dev's content and messages imported from Velox, then imported again with no write and no event, as a Switch-over rehearsed twice.
it('imports aguet.dev from a Velox-shaped source, then replays it with no write and no event', function (): void {
    installAguetDev();
    config(['kami.forms' => [ContactForm::class]]);

    $first = importVelox();
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^(?:insert into|update|delete from) ["`]?(\w+)/i', $query->sql, $match) === 1) {
            $writes[] = substr($match[1], strlen($query->connection->getTablePrefix()));
        }
    });
    $events = [];
    Event::listen('*', function (string $event) use (&$events): void {
        // Reads and queries are no events of Kami's.
        if (! str_starts_with($event, 'eloquent.retrieved') && ! str_starts_with($event, 'Illuminate\\Database\\')) {
            $events[] = $event;
        }
    });
    $replay = importVelox();

    expect([$first->created, $first->updated, $first->unchanged])->toBe([8, 0, 2])
        ->and([$replay->created, $replay->updated, $replay->unchanged, $replay->unseen])->toBe([0, 0, 10, 0])
        ->and(array_values(array_unique($writes)))->toBe(['kami_import_keys', 'kami_audit_events']);
    // The run's one summary, and nothing per item.
    expect(array_values(array_unique($events)))->toBe([AuditEventRecorded::class]);
    $cvci = Entry::query()->ofType(Project::class)->get()->firstWhere('title', 'CVCI');
    expect($cvci->tags->pluck('title')->all())->toBe(['Vue', 'PHP', 'Laravel'])
        ->and($cvci->created_at->toDateTimeString())->toBe('2025-02-01 09:00:00')
        ->and(Media::query()->sole()->created_at->toDateTimeString())->toBe('2025-01-11 09:00:00')
        ->and(Submission::query()->orderBy('created_at')->get()->map->only(['locale', 'read_at'])->toArray())->toEqual([
            ['locale' => 'fr', 'read_at' => CarbonImmutable::parse('2025-04-01 09:00:00')],
            ['locale' => 'en', 'read_at' => null],
        ])
        ->and(DB::table('kami_form_deliveries')->count())->toBe(0);
});
