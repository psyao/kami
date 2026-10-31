<?php

declare(strict_types=1);

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\AvatarProviders\UiAvatarsProvider;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as PlainUser;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Psyao\Kami\Admin\InitialsAvatarProvider;
use Psyao\Kami\Admin\KamiPlugin;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\Fields\Select;
use Psyao\Kami\Content\Fields\Text;
use Psyao\Kami\Support\Internal\Doctor\Doctor;
use Psyao\Kami\Support\SecurityHeaders;
use Symfony\Component\HttpFoundation\Response;

/** A type with no view and no label. */
final class Undescribed extends ContentType
{
    /** @var list<Field>|null */
    public static ?array $fields = null;

    public function fields(): array
    {
        return self::$fields ?? [Text::make('note')];
    }
}

/** A type that renders without a view. */
final class Rendered extends ContentType
{
    public function render(Entry $entry): Response
    {
        return response('');
    }
}

// An empty database, whatever the engine: tables another test left on MySQL or MariaDB, which do not roll back, would read as drift. `ContentModelDriftTest` covers the engines.
beforeEach(function () {
    config(['database.connections.empty' => ['driver' => 'sqlite', 'database' => ':memory:'], 'database.default' => 'empty']);
});

function temporaryPath(): string
{
    return sys_get_temp_dir() . '/kami-doctor-' . uniqid();
}

function withoutExtensions(string ...$missing): Doctor
{
    return new Doctor(extensionLoaded: fn (string $extension) => ! in_array($extension, $missing, true));
}

function withSqliteDatabase(string $path): void
{
    // Whatever engine the suite runs on.
    config(['database.default' => 'testing', 'database.connections.testing.database' => $path]);
    DB::purge('testing');
}

/** A path that exists but that SQLite cannot open: a directory. */
function withUnreachableDatabase(): void
{
    File::ensureDirectoryExists($directory = temporaryPath());
    withSqliteDatabase($directory);
}

it('finds no problem in a sound environment, without a terminal', function () {
    $this->artisan('kami:doctor', ['--no-interaction' => true])
        ->expectsOutputToContain('No problem found')
        ->assertSuccessful();
});

it('fails on a PHP version below the floor, with the fix and the guide', function () {
    $this->app->instance(Doctor::class, new Doctor(phpVersion: '8.4.13'));

    $this->artisan('kami:doctor')
        ->expectsOutputToContain('PHP 8.4.13 is below Kami\'s floor, 8.5.0')
        ->expectsOutputToContain('Fix: run PHP 8.5.0 or later')
        ->expectsOutputToContain('docs/guide/installation.md#requirements')
        ->assertFailed();
});

it('fails on a missing required extension', function () {
    $this->app->instance(Doctor::class, withoutExtensions('intl'));

    $this->artisan('kami:doctor')
        ->expectsOutputToContain('The PHP extension intl is not loaded')
        ->expectsOutputToContain('docs/guide/installation.md#requirements')
        ->assertFailed();
});

it('warns without failing when no image extension is loaded', function () {
    $this->app->instance(Doctor::class, withoutExtensions('gd', 'imagick'));

    $this->artisan('kami:doctor')
        ->expectsOutputToContain('Neither gd nor imagick is loaded')
        ->assertSuccessful();
});

it('fails on a warning with --strict', function () {
    $this->app->instance(Doctor::class, withoutExtensions('gd', 'imagick'));

    $this->artisan('kami:doctor', ['--strict' => true])
        ->expectsOutputToContain('Neither gd nor imagick is loaded')
        ->assertFailed();
});

it('fails on a directory Kami cannot write to', function (string $directory) {
    $missing = temporaryPath() . '/missing';
    match ($directory) {
        'storage' => $this->app->useStoragePath($missing),
        'bootstrap/cache' => $this->app->useBootstrapPath($missing),
    };

    $this->artisan('kami:doctor')
        ->expectsOutputToContain('is not writable')
        ->expectsOutputToContain('docs/guide/installation.md#writable-directories')
        ->assertFailed();

    expect($missing)->not->toBeDirectory();
})->with(['storage', 'bootstrap/cache']);

it('fails when the database cannot be reached', function () {
    withUnreachableDatabase();

    $this->artisan('kami:doctor')
        ->expectsOutputToContain('Kami cannot connect to the database testing')
        ->expectsOutputToContain('the PHP extension pdo_sqlite')
        ->expectsOutputToContain('docs/guide/installation.md#database')
        ->assertFailed();
});

it('names quoting and the cached configuration when the database refuses the credentials', function (string $driver, string $refusal) {
    config(['database.default' => 'refused', 'database.connections.refused' => ['driver' => $driver]]);
    DB::extend('refused', fn () => throw new PDOException($refusal));

    $problem = collect((new Doctor)->problems())->sole();

    expect($problem->message)->toStartWith('Kami cannot connect to the database refused')
        ->and($problem->fix)->toContain("DB_PASSWORD='...'", 'php artisan optimize')
        ->and($problem->guide)->toBe('installation.md#database');
})->with([
    'MariaDB' => ['mariadb', "SQLSTATE[HY000] [1045] Access denied for user 'site'@'host' (using password: YES)"],
    'PostgreSQL' => ['pgsql', 'SQLSTATE[08006] [7] connection to server failed: FATAL:  password authentication failed for user "site"'],
]);

it('accepts a missing SQLite file in a writable directory, which migrate creates', function () {
    File::ensureDirectoryExists($directory = temporaryPath());
    withSqliteDatabase("{$directory}/database.sqlite");

    expect((new Doctor)->problems())->toBeEmpty()
        ->and("{$directory}/database.sqlite")->not->toBeFile();

    File::deleteDirectory($directory);
});

it('fails on a missing SQLite file whose directory is missing or not writable', function (bool $exists) {
    $directory = temporaryPath();
    if ($exists) {
        File::ensureDirectoryExists($directory, 0555);
    }
    withSqliteDatabase("{$directory}/database.sqlite");

    $this->artisan('kami:doctor')
        ->expectsOutputToContain("Kami cannot create the SQLite database testing: {$directory} is missing or not writable")
        ->expectsOutputToContain("Fix: create {$directory} if it is missing")
        ->expectsOutputToContain('docs/guide/installation.md#database')
        ->assertFailed();

    expect("{$directory}/database.sqlite")->not->toBeFile()
        ->and(is_dir($directory))->toBe($exists);

    File::deleteDirectory($directory);
})->with(['missing directory' => false, 'unwritable directory' => true]);

it('fails when the database connection is not configured', function () {
    config(['database.default' => 'missing']);

    $this->artisan('kami:doctor')
        ->expectsOutputToContain('Kami cannot connect to the database missing')
        ->assertFailed();
});

it('warns without failing when a Kami panel keeps Filament\'s remote avatars', function (?string $provider) {
    $panel = Panel::make()->id('remote')->plugin(KamiPlugin::make());
    if ($provider !== null) {
        $panel->defaultAvatarProvider($provider);
    }

    $this->artisan('kami:doctor')
        ->expectsOutputToContain('The panel remote draws avatars from ui-avatars.com')
        ->expectsOutputToContain('docs/guide/installation.md#installing-and-deploying')
        ->assertSuccessful();

    expect((new Doctor)->blocking())->toBeEmpty();
})->with([
    'Filament\'s default' => null,
    'a subclass of it' => (new class extends UiAvatarsProvider {})::class,
]);

it('reports a panel once, even with the plugin added twice', function () {
    Panel::make()->id('remote')->strictAuthorization()->middleware(['kami.security-headers:admin'])->plugin(KamiPlugin::make())->plugin(KamiPlugin::make());

    expect((new Doctor)->problems())->toHaveCount(1);
});

it('accepts a Kami panel with a local avatar provider, even set after the plugin', function (string $provider) {
    Panel::make()->id('local')->strictAuthorization()->middleware(['kami.security-headers:admin'])->plugin(KamiPlugin::make())->defaultAvatarProvider($provider);

    expect((new Doctor)->problems())->toBeEmpty();
})->with([
    'Kami\'s' => InitialsAvatarProvider::class,
    'the host\'s own' => (new class implements AvatarProvider
    {
        public function get(Model $record): string
        {
            return '';
        }
    })::class,
]);

it('never reports a panel without Kami', function () {
    Panel::make()->id('other');

    expect((new Doctor)->problems())->toBeEmpty();
});

it('writes its last result under Kami\'s storage directory', function () {
    $this->app->instance(Doctor::class, withoutExtensions('gd', 'imagick'));
    // A storage of its own: parallel processes share the skeleton's.
    $this->app->useStoragePath($storage = temporaryPath());
    File::ensureDirectoryExists($storage);

    $this->artisan('kami:doctor')->assertSuccessful();

    $result = File::json("{$storage}/framework/kami/doctor.json");
    expect($result['checked_at'])->toBeString()
        ->and($result['problems'])->toHaveCount(1)
        ->and($result['problems'][0])->toMatchArray(['severity' => 'warning', 'guide' => 'installation.md#requirements']);

    File::deleteDirectory($storage);
});

it('keeps Kami\'s storage directory out of git, as Laravel does its own', function () {
    $this->app->useStoragePath($storage = temporaryPath());
    File::ensureDirectoryExists($storage);

    $this->artisan('kami:doctor');

    expect(File::get("{$storage}/framework/kami/.gitignore"))->toBe("*\n!.gitignore\n");

    File::deleteDirectory($storage);
});

it('offers its environment errors alone, for kami:install to block on', function () {
    $this->app->useStoragePath(temporaryPath() . '/missing');
    withUnreachableDatabase();
    $doctor = new Doctor(phpVersion: '8.4.13', extensionLoaded: fn (string $extension) => ! in_array($extension, ['intl', 'gd', 'imagick'], true));

    $blocking = collect($doctor->blocking());

    expect($blocking->pluck('message')->map(fn (string $message) => Str::before($message, ':')))->toEqual(collect([
        'PHP 8.4.13 is below Kami\'s floor, 8.5.0.',
        'The PHP extension intl is not loaded.',
        'storage (' . storage_path() . ') is not writable.',
        'Kami cannot connect to the database testing',
    ]));
});

it('links every problem to a section of the guide', function () {
    $this->app->useStoragePath(temporaryPath() . '/missing');
    withUnreachableDatabase();
    $everything = new Doctor(phpVersion: '8.4.13', extensionLoaded: fn () => false);
    Panel::make()->id('remote')->plugin(KamiPlugin::make());
    config(['auth.providers.users.model' => PlainUser::class, 'app.env' => 'production', 'kami.security' => [
        'csp' => ['script-src' => ["'unsafe-eval'"], 'base-uri' => ["'self'"]],
        'headers' => ['X-Frame-Options' => null, 'Referrer-Policy' => 'unsafe-url'],
        'csp_report_uri' => 'https://reports.example.com/csp',
        'csp_report_only' => true,
    ]]);
    app('router')->middlewareGroup('web', []);
    File::put($hot = temporaryPath(), 'http://[::1]:5173');
    Vite::useHotFile($hot);

    foreach ($everything->problems() as $problem) {
        [$page, $anchor] = explode('#', $problem->guide);
        expect(headingAnchors("docs/guide/{$page}"))->toContain($anchor);
    }
});

it('requires the PHP floor and every extension Kami\'s dependencies require', function () {
    $composer = File::json(dirname(__DIR__, 2) . '/composer.json');
    $installed = File::json(dirname(__DIR__, 2) . '/vendor/composer/installed.json');
    $runtime = collect($installed['packages'])->reject(fn (array $package) => in_array($package['name'], $installed['dev-package-names'], true));
    $required = $runtime->flatMap(fn (array $package) => array_keys($package['require'] ?? []))
        ->filter(fn (string $requirement) => str_starts_with($requirement, 'ext-'))
        ->map(fn (string $requirement) => Str::after($requirement, 'ext-'))
        ->unique();

    expect($composer['require']['php'])->toBe('^' . Str::beforeLast(Doctor::PHP_FLOOR, '.0'))
        ->and(Doctor::EXTENSIONS)->toContain(...$required);
});

it('blocks on a panel whose User model does not implement KamiUser, naming what to add', function () {
    config(['auth.providers.users.model' => PlainUser::class]);

    $this->artisan('kami:doctor')
        ->expectsOutputToContain('The panel admin signs Users in with Illuminate\\Foundation\\Auth\\User, which does not implement Psyao\\Kami\\Users\\KamiUser')
        ->expectsOutputToContain('Fix: add implements \\Psyao\\Kami\\Users\\KamiUser to the User model, and use \\Psyao\\Kami\\Users\\HasKamiRoles; in its body.')
        ->expectsOutputToContain('docs/guide/users-and-roles.md#the-user-model')
        ->assertFailed();

    expect((new Doctor)->blocking())->toHaveCount(1);
});

it('warns when a Kami panel does not authorize strictly, even when turned off after the plugin', function () {
    Filament::getPanel('admin')->strictAuthorization(false);

    $this->artisan('kami:doctor')
        ->expectsOutputToContain('The panel admin does not authorize strictly')
        ->expectsOutputToContain('Fix: add ->strictAuthorization() to the panel admin')
        ->expectsOutputToContain('docs/guide/users-and-roles.md#strict-authorization')
        ->assertSuccessful();
});

it('warns when the web group lacks SecurityHeaders', function () {
    app('router')->middlewareGroup('web', []);

    $this->artisan('kami:doctor')
        ->expectsOutputToContain('The web middleware group does not send Kami\'s security headers')
        ->expectsOutputToContain('Fix: append \\Psyao\\Kami\\Support\\SecurityHeaders::class to the web group in bootstrap/app.php')
        ->expectsOutputToContain('docs/guide/security.md#your-own-pages')
        ->assertSuccessful();
});

it('reads the web group as bootstrap/app.php sets it, which reaches the router with the HTTP kernel only', function () {
    // On the command line nothing builds the HTTP kernel, so the router's `web` group lacks what bootstrap/app.php appends.
    app('router')->middlewareGroup('web', []);
    $this->app->forgetInstance(HttpKernelContract::class);
    $this->app->afterResolving(HttpKernelContract::class, fn (HttpKernel $kernel) => $kernel->appendMiddlewareToGroup('web', SecurityHeaders::class));

    $this->artisan('kami:doctor')->doesntExpectOutputToContain('security headers')->assertSuccessful();
});

it('accepts SecurityHeaders in the web group by class or by alias', function (string $middleware) {
    app('router')->middlewareGroup('web', [$middleware]);

    $this->artisan('kami:doctor')->doesntExpectOutputToContain('security headers')->assertSuccessful();
})->with([SecurityHeaders::class, 'kami.security-headers']);

it('warns when a Kami panel lacks the admin profile of SecurityHeaders', function (array $middleware) {
    Panel::make()->id('bare')->strictAuthorization()->defaultAvatarProvider(InitialsAvatarProvider::class)->middleware($middleware)->plugin(KamiPlugin::make());

    $this->artisan('kami:doctor')
        ->expectsOutputToContain('The panel bare does not send Kami\'s admin security headers')
        ->expectsOutputToContain("Fix: add 'kami.security-headers:admin' to the ->middleware() of the panel bare.")
        ->expectsOutputToContain('docs/guide/security.md#the-admin')
        ->assertSuccessful();
})->with([
    'none' => [[]],
    // The front profile blocks Filament's scripts.
    'the front profile' => [[SecurityHeaders::class]],
    'the front profile by alias' => [['kami.security-headers:front']],
]);

it('warns for each weakening security option that is on', function () {
    config(['kami.security' => [
        'csp' => ['script-src' => ["'unsafe-inline'", 'https://cdn.example.com'], 'frame-ancestors' => 'https://partner.example.com', 'img-src' => ['https://cdn.example.com']],
        'headers' => ['x-frame-options' => null, 'Referrer-Policy' => 'unsafe-url', 'Strict-Transport-Security' => 'max-age=31536000'],
        'csp_report_uri' => 'https://reports.example.com/csp',
        'csp_report_only' => true,
    ]]);

    $this->artisan('kami:doctor')
        ->expectsOutputToContain("kami.security.csp adds 'unsafe-inline' to script-src")
        ->expectsOutputToContain("kami.security.csp opens frame-ancestors, which Kami sets to 'none'")
        ->expectsOutputToContain('kami.security.headers omits X-Frame-Options')
        ->expectsOutputToContain('kami.security.headers replaces Kami\'s Referrer-Policy')
        ->expectsOutputToContain('kami.security.csp_report_uri sends')
        ->expectsOutputToContain('kami.security.csp_report_only is on')
        ->doesntExpectOutputToContain('img-src')
        ->doesntExpectOutputToContain('Strict-Transport-Security')
        ->assertSuccessful();
});

it('fails on a Vite hot file in production, not elsewhere', function (string $environment, bool $fails) {
    File::put($hot = temporaryPath(), 'http://[::1]:5173');
    Vite::useHotFile($hot);
    config(['app.env' => $environment]);

    $doctor = $this->artisan('kami:doctor');
    $fails
        ? $doctor->expectsOutputToContain("{$hot} exists in production")->expectsOutputToContain('docs/guide/security.md#vite-in-development')->assertFailed()
        : $doctor->doesntExpectOutputToContain('exists in production')->assertSuccessful();
})->with([
    'production' => ['production', true],
    'local' => ['local', false],
]);

it('warns about a type whose view is missing, unless it renders without one', function () {
    config(['kami.types' => [Undescribed::class, Rendered::class]]);

    $this->artisan('kami:doctor')
        ->expectsOutputToContain('The Content Type undescribed has no view kami.undescribed')
        ->expectsOutputToContain('Fix: create resources/views/kami/undescribed.blade.php')
        ->expectsOutputToContain('docs/guide/routing-and-rendering.md#rendering')
        ->doesntExpectOutputToContain('kami.rendered')
        ->assertSuccessful();
});

it('lists each Select option with no label', function () {
    Undescribed::$fields = [Select::make('kind')->options(['news'])];
    config(['kami.types' => [Undescribed::class]]);

    $this->artisan('kami:doctor')->expectsOutputToContain('undescribed.fields.kind.options.news');

    Undescribed::$fields = null;
});

it('warns about each missing label key, per language, leaving help texts optional', function () {
    config(['kami.types' => [Undescribed::class]]);
    app('translator')->addLines(['kami-types.undescribed.label' => 'Undescribed'], 'fr');

    $this->artisan('kami:doctor')
        ->expectsOutputToContain('lang/en/kami-types.php lacks 3 label keys: undescribed.label, undescribed.plural, undescribed.fields.note.label')
        ->expectsOutputToContain('lang/fr/kami-types.php lacks 2 label keys: undescribed.plural, undescribed.fields.note.label')
        ->expectsOutputToContain('docs/guide/content-types.md#labels')
        ->doesntExpectOutputToContain('.help')
        ->assertSuccessful();
});
