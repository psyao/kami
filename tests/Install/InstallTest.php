<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Singleton;
use Psyao\Kami\Content\SiteSettings;
use Psyao\Kami\Routing\Internal\PageCache;
use Psyao\Kami\Support\Internal\Doctor\Doctor;
use Psyao\Kami\Tests\Install\InstallTestCase as TestCase;
use Psyao\Kami\Users\HasKamiRoles;
use Psyao\Kami\Users\KamiUser;
use Workbench\App\Content\Article;
use Workbench\App\Content\Footer;
use Workbench\App\Content\Home;
use Workbench\App\Content\Label;
use Workbench\App\Models\User as WorkbenchUser;

/** A second Singleton on Home's address. */
final class TakenHome extends Singleton
{
    #[Override]
    protected ?string $prefix = '';
}

/** A User model a host panel may sign in with through a provider of its own. */
final class InstallEditor extends WorkbenchUser
{
    protected $table = 'users';
}

/** A User model with Roles but nowhere to store an authenticator. */
final class InstallUserWithoutMfa extends User implements KamiUser
{
    use HasKamiRoles;

    protected $table = 'users';
}

/**
 * Each file of the skeleton with a hash of its contents, minus the directories Laravel writes to at run time.
 *
 * @return array<string, string>
 */
function skeletonFiles(): array
{
    return collect(File::allFiles(base_path(), hidden: true))
        ->mapWithKeys(fn (SplFileInfo $file) => [Str::after($file->getPathname(), base_path() . '/') => md5_file($file->getPathname())])
        ->reject(fn (string $hash, string $path) => Str::startsWith($path, ['storage/', 'bootstrap/cache/']))
        ->all();
}

it('installs Kami on SQLite without a terminal', function () {
    $this->bootWithKamiOnAPanel();

    install()->assertSuccessful();

    expect(Schema::hasTable('migrations'))->toBeTrue()
        ->and(public_path('css/psyao/kami/kami.css'))->toBeFile()
        ->and(public_path('js/psyao/kami/kami.js'))->toBeFile()
        ->and(public_path('js/filament/filament/app.js'))->toBeFile();
});

it('makes a new Content version, as views may have changed, with the next visibility change a deploy may have left stale', function () {
    $this->bootWithTypes([Article::class]);
    $this->app->useStoragePath($storage = temporaryPath());
    File::ensureDirectoryExists($storage);
    install()->assertSuccessful();
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Later', 'author' => 'Ada', 'status' => 'published', 'published_at' => '2099-01-01 09:00']);
    $before = PageCache::version();
    File::put("{$storage}/framework/kami/pages/manifest.json", json_encode(['version' => $before, 'next_change_at' => null]));
    File::put("{$storage}/framework/kami/visibility-default.json", '{"next_change_at":null}');

    install()->assertSuccessful();

    expect("{$storage}/framework/kami/visibility-default.json")->not->toBeFile()
        ->and(PageCache::manifest()['version'] ?? null)->not->toBe($before)
        ->and(PageCache::manifest()['next_change_at'] ?? null)->toBe(CarbonImmutable::parse('2099-01-01 09:00', 'UTC')->getTimestamp());

    File::deleteDirectory($storage);
});

it('creates a missing SQLite file, as on a first deploy from git', function () {
    $this->bootWithKamiOnAPanel();
    File::ensureDirectoryExists($directory = sys_get_temp_dir() . '/kami-install-' . uniqid());
    config(['database.connections.testing.database' => "{$directory}/database.sqlite"]);
    DB::purge('testing');

    install()->assertSuccessful();

    expect("{$directory}/database.sqlite")->toBeFile()
        ->and(Schema::hasTable('migrations'))->toBeTrue();

    File::deleteDirectory($directory);
});

it('refuses, with the lines to add, while no panel carries KamiPlugin', function () {
    install()
        ->expectsOutputToContain('use Psyao\Kami\Admin\KamiPlugin;')
        ->expectsOutputToContain('->plugin(KamiPlugin::make())')
        ->assertFailed();

    expect(Schema::hasTable('migrations'))->toBeFalse()
        ->and(public_path('js'))->not->toBeDirectory();
});

it('fails before migrating on a blocking environment error', function (Closure $break, string $message) {
    $this->bootWithKamiOnAPanel();
    $break();

    install()->expectsOutputToContain($message)->assertFailed();

    expect(rescue(fn () => Schema::hasTable('migrations'), false, report: false))->toBeFalse()
        ->and(public_path('js'))->not->toBeDirectory();
})->with([
    'PHP below the floor' => [fn () => app()->instance(Doctor::class, new Doctor(phpVersion: '8.4.13')), 'PHP 8.4.13 is below Kami\'s floor'],
    'missing extension' => [fn () => app()->instance(Doctor::class, new Doctor(extensionLoaded: fn (string $extension) => $extension !== 'intl')), 'The PHP extension intl is not loaded'],
    'unwritable storage' => [fn () => app()->useStoragePath(sys_get_temp_dir() . '/kami-install-' . uniqid() . '/missing'), 'is not writable'],
    'SQLite directory missing' => [function () {
        config(['database.connections.testing.database' => sys_get_temp_dir() . '/kami-install-' . uniqid() . '/missing.sqlite']);
        DB::purge('testing');
    }, 'Kami cannot create the SQLite database testing'],
]);

it('refuses before migrating, naming what to add, while the panel\'s User model lacks KamiUser', function () {
    $this->bootWithKamiOnAPanel();
    config(['auth.providers.users.model' => User::class]);

    install()
        ->expectsOutputToContain('The panel admin signs Users in with Illuminate\\Foundation\\Auth\\User, which does not implement Psyao\\Kami\\Users\\KamiUser')
        ->expectsOutputToContain('Fix: add implements \\Psyao\\Kami\\Users\\KamiUser to the User model, and use \\Psyao\\Kami\\Users\\HasKamiRoles; in its body.')
        ->assertFailed();

    expect(Schema::hasTable('migrations'))->toBeFalse();
});

it('refuses before migrating, naming what to add, while the panel\'s User model cannot store an authenticator', function () {
    $this->bootWithKamiOnAPanel();
    config(['auth.providers.users.model' => InstallUserWithoutMfa::class]);

    install()
        ->expectsOutputToContain('The panel admin signs Users in with InstallUserWithoutMfa, which cannot store an authenticator')
        ->expectsOutputToContain('Fix: add implements \\Filament\\Auth\\MultiFactor\\App\\Contracts\\HasAppAuthentication, \\Filament\\Auth\\MultiFactor\\App\\Contracts\\HasAppAuthenticationRecovery to the User model, and use \\Psyao\\Kami\\Admin\\HasKamiMfa; in its body.')
        ->assertFailed();

    expect(Schema::hasTable('migrations'))->toBeFalse();
});

it('refuses before migrating while the panel signs in with another model than auth.providers.users.model', function () {
    $this->bootWithKamiOnAPanel();
    config([
        'auth.providers.editors' => ['driver' => 'eloquent', 'model' => InstallEditor::class],
        'auth.guards.editors' => ['driver' => 'session', 'provider' => 'editors'],
    ]);
    Filament::getPanel('admin')->authGuard('editors');

    install()
        ->expectsOutputToContain('The panel admin signs Users in with InstallEditor, while Kami gives Roles to')
        ->assertFailed();

    expect(Schema::hasTable('migrations'))->toBeFalse();
});

it('changes nothing when run again', function () {
    $this->bootWithKamiOnAPanel();
    install()->assertSuccessful();
    $files = skeletonFiles();
    $migrations = DB::table('migrations')->get();

    install()->assertSuccessful();

    expect(skeletonFiles())->toBe($files)
        ->and(DB::table('migrations')->get())->toEqual($migrations);
});

/** A site with a Singleton and a Global Set of its own, their tables migrated by `kami:install`. */
function bootWithSeededTypes(TestCase $test, array $types = [Home::class, Footer::class]): void
{
    $test->bootWithTypes($types);
}

describe('seeding', function () {
    it('creates a Singleton as a Draft titled with its label, a Global Set published, with no actor', function () {
        bootWithSeededTypes($this);
        install()->assertSuccessful();

        expect(Home::entry())->toBeNull();

        $home = Entry::query()->withUnpublished()->ofType(Home::class)->sole();
        $footer = Footer::entry();

        expect($home->title)->toBe('Home')
            ->and($home->status)->toBe('draft')
            ->and($home->created_by)->toBeNull()
            ->and($footer?->status)->toBe('published')
            ->and($footer?->translations->pluck('locale')->all())->toBe([config('kami.sites.default.locales')[0]])
            ->and(SiteSettings::entry()?->status)->toBe('published');
    });

    it('titles a Singleton in the default Locale, whatever app.locale says', function () {
        bootWithSeededTypes($this);
        config(['app.locale' => 'en', 'kami.sites.default.locales' => ['fr', 'en']]);
        install()->assertSuccessful();

        expect(SiteSettings::entry()?->title)->toBe('Réglages du site');
    });

    it('fails, naming the command, on a declared type with no table', function () {
        $this->bootWithKamiOnAPanel();
        config(['kami.types' => [Footer::class]]);

        install()
            ->expectsOutputToContain('kami:make:migration footer')
            ->assertFailed();
    });

    it('changes no content when run again, and leaves an edited Entry alone', function () {
        bootWithSeededTypes($this);
        install()->assertSuccessful();

        resolve(SaveEntry::class)->handle(Footer::class, ['tagline' => 'Edited'], Footer::entry());
        $tables = fn (): array => collect(['kami_entries', 'kami_translations', 'kami_paths', 'kami_type_footer'])->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('entry_id')->get()->toArray()])->all();
        $before = $tables();

        install()->assertSuccessful();

        expect($tables())->toEqual($before)
            ->and(Footer::entry()?->tagline)->toBe('Edited');
    });

    it('fails the deploy, naming the type, on an address another Entry holds', function () {
        bootWithSeededTypes($this, [Home::class, TakenHome::class]);

        install()
            ->expectsOutputToContain('taken_home')
            ->assertFailed();
    });
});

it('writes no file of the host\'s, only Filament\'s public assets', function () {
    $this->bootWithKamiOnAPanel();
    $before = skeletonFiles();

    install()->assertSuccessful();

    $after = skeletonFiles();
    $added = array_keys(array_diff_key($after, $before));
    expect(array_intersect_key($after, $before))->toBe($before)
        ->and($added)->not->toBeEmpty()
        ->and(array_filter($added, fn (string $path) => ! str_starts_with($path, 'public/')))->toBeEmpty();
});

describe('public/media', function () {
    beforeEach(function () {
        $this->bootWithKamiOnAPanel();
        config(['filesystems.disks.public.root' => $this->disk = temporaryPath()]);
    });

    it('links public/media to the public disk\'s media directory, with Kami\'s headers for Apache', function () {
        install()->assertSuccessful();

        expect(readlink(public_path('media')))->toBe("{$this->disk}/media")
            ->and(File::get(public_path('media/.htaccess')))
            ->toContain('Header always set X-Content-Type-Options "nosniff"')
            ->toContain('Header set Cache-Control "public, max-age=31536000, immutable"')
            ->toContain('<FilesMatch "\.svg$">')
            ->toContain("Header always set Content-Security-Policy \"default-src 'none'; style-src 'unsafe-inline'; img-src data:\"")
            ->not->toContain('RewriteEngine');
    });

    it('keeps the files Apache serves out of search engines while the Site is not indexable', function (bool $indexable) {
        config(['kami.indexable' => $indexable]);

        install()->assertSuccessful();

        expect(str_contains(File::get(public_path('media/.htaccess')), "\n    Header always set X-Robots-Tag \"noindex, nofollow\"\n"))->toBe(! $indexable);
    })->with(['indexable' => true, 'not indexable' => false]);

    it('writes its .htaccess again at each run, as Kami\'s file', function () {
        install()->assertSuccessful();
        File::put(public_path('media/.htaccess'), 'edited');
        File::put(public_path('media/kept.jpg'), 'a Conversion');

        install()->assertSuccessful();

        expect(File::get(public_path('media/.htaccess')))->toContain('nosniff')
            ->and(File::get("{$this->disk}/media/kept.jpg"))->toBe('a Conversion');
    });

    it('moves a link that points elsewhere, leaving its former target alone', function () {
        File::ensureDirectoryExists($former = temporaryPath());
        File::put("{$former}/old.jpg", 'old');
        File::link($former, public_path('media'));

        install()->assertSuccessful();

        expect(readlink(public_path('media')))->toBe("{$this->disk}/media")
            ->and("{$former}/old.jpg")->toBeFile();
    });

    it('fails, touching nothing, on a public/media that is no link', function () {
        File::ensureDirectoryExists(public_path('media'));
        File::put(public_path('media/old.jpg'), 'old');

        install()->expectsOutputToContain('public/media is a directory, not a link')->assertFailed();

        expect(is_link(public_path('media')))->toBeFalse()
            ->and(File::files(public_path('media')))->toHaveCount(1);
    });

    it('fails before migrating on an originals disk Apache would serve, metadata included', function () {
        config(['kami.media.originals_disk' => 'public']);

        install()->expectsOutputToContain('kami.media.originals_disk is public')->assertFailed();

        expect(file_exists(public_path('media')))->toBeFalse()
            ->and(Schema::hasTable('kami_media'))->toBeFalse();
    });

    it('fails on a public disk that is not local, which Apache cannot serve from', function () {
        config(['filesystems.disks.remote' => ['driver' => 's3', 'bucket' => 'b', 'region' => 'eu-west-1'], 'kami.media.public_disk' => 'remote']);

        install()->expectsOutputToContain('kami.media.public_disk is remote, whose driver is s3')->assertFailed();

        expect(file_exists(public_path('media')))->toBeFalse();
    });
});

describe('public/robots.txt', function () {
    beforeEach(function () {
        $this->bootWithKamiOnAPanel();
        config(['app.url' => 'https://example.test', 'kami.sites.default.url' => 'https://example.test', 'kami.indexable' => true]);
    });

    it('writes the managed file: the panel disallowed and the sitemap named, or everything disallowed when not indexable', function (bool $indexable, string $rules) {
        config(['kami.indexable' => $indexable]);

        install()->expectsOutputToContain('Wrote public/robots.txt.')->assertSuccessful();

        expect(File::get(public_path('robots.txt')))->toBe("# Managed by Kami (kami:install). Delete this line to own this file.\nUser-agent: *\n{$rules}");
    })->with([
        'indexable' => [true, "Disallow: /admin\n\nSitemap: https://example.test/sitemap.xml\n"],
        'not indexable' => [false, "Disallow: /\n"],
    ]);

    it('disallows no panel at the root or on a domain of its own', function (string $path, array $domains) {
        Filament::getPanel('admin')->path($path)->domains($domains);

        install()->assertSuccessful();

        expect(File::get(public_path('robots.txt')))->toBe("# Managed by Kami (kami:install). Delete this line to own this file.\nUser-agent: *\n\nSitemap: https://example.test/sitemap.xml\n");
    })->with([
        'root' => ['', []],
        'own domain' => ['admin', ['admin.example.test']],
    ]);

    it('rewrites a file starting with the marker, and never touches one without it', function () {
        File::put(public_path('robots.txt'), "# Managed by Kami (kami:install). Delete this line to own this file.\nUser-agent: *\nDisallow: /\n");
        install()->assertSuccessful();
        expect(File::get(public_path('robots.txt')))->toContain('Sitemap: https://example.test/sitemap.xml');

        File::put(public_path('robots.txt'), "User-agent: *\nDisallow: /private\n");
        install()->expectsOutputToContain('public/robots.txt is the site\'s own')->assertSuccessful();
        expect(File::get(public_path('robots.txt')))->toBe("User-agent: *\nDisallow: /private\n");
    });

    it('writes nothing when a route of the application answers /robots.txt, which the file would shadow', function () {
        Route::get('robots.txt', fn () => 'User-agent: *');

        install()->expectsOutputToContain('a route of the application answers /robots.txt')->assertSuccessful();

        expect(public_path('robots.txt'))->not->toBeFile();
    });
});

describe('the sitemap', function () {
    beforeEach(function () {
        $this->bootWithTypes([Label::class]);
        // Its state lives in storage/, which the skeleton keeps from one run to the next.
        $this->app->useStoragePath($this->storage = temporaryPath());
        File::ensureDirectoryExists($this->storage);
        config(['kami.sites.default.locales' => ['fr']]);
    });

    afterEach(function () {
        File::deleteDirectory($this->storage);
    });

    // A Tick's hourly `kami:sitemap` rebuilds it, and a missing one is built on its first request (ADR 0027).
    it('builds no sitemap', function () {
        install()->assertSuccessful();
        resolve(SaveEntry::class)->handle(Label::class, ['title' => 'Partout', 'status' => 'published']);

        install()->assertSuccessful();

        expect(public_path('sitemap.xml'))->not->toBeFile();
    });

    it('makes the sitemap stale when Paths follow a new Locale', function () {
        $this->travelTo('2026-11-08 10:00:00');
        install()->assertSuccessful();
        resolve(SaveEntry::class)->handle(Label::class, ['title' => 'Partout', 'status' => 'published']);
        $this->travelTo('2026-11-08 10:01:00');
        $this->artisan('kami:sitemap')->expectsOutputToContain('Wrote the sitemap: 1 URLs.')->assertSuccessful();

        $this->travelTo('2026-11-08 10:02:00');
        config(['kami.sites.default.locales' => ['fr', 'en']]);
        install()->assertSuccessful();
        $this->artisan('kami:sitemap')->expectsOutputToContain('Wrote the sitemap: 2 URLs.')->assertSuccessful();
    });
});

it('has no kami:upgrade: kami:install serves every deploy', function () {
    expect(Artisan::all())->toHaveKey('kami:install')->not->toHaveKey('kami:upgrade');
});

// Here rather than in the parallel `Kami` suite: `view:cache` clears the compiled views other workers render from.
it('lets the site cache its views, as `php artisan optimize` does', function () {
    $this->bootWithKamiOnAPanel();

    $this->artisan('view:cache')->assertSuccessful();
    $this->artisan('view:clear')->assertSuccessful();
});
