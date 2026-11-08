<?php

declare(strict_types=1);

use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\Login;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\AvatarProviders\UiAvatarsProvider;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as PlainUser;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Psyao\Kami\Admin\InitialsAvatarProvider;
use Psyao\Kami\Admin\KamiPlugin;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\Fields\Select;
use Psyao\Kami\Content\Fields\Text;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Media\Internal\ImageFormats;
use Psyao\Kami\Support\Internal\Doctor\Doctor;
use Psyao\Kami\Support\Internal\Doctor\Severity;
use Psyao\Kami\Support\SecurityHeaders;
use Psyao\Kami\Users\HasKamiRoles;
use Psyao\Kami\Users\KamiUser;
use Symfony\Component\HttpFoundation\Response;
use Workbench\App\Models\User;

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

/** A User model with Roles but nowhere to store an authenticator. */
final class UserWithoutMfa extends PlainUser implements KamiUser
{
    use HasKamiRoles;

    protected $table = 'users';
}

/** A User model a host panel may sign in with through a provider of its own. */
final class Editor extends User
{
    protected $table = 'users';
}

// An empty database, whatever the engine: tables another test left on MySQL or MariaDB, which do not roll back, would read as drift. `ContentModelDriftTest` covers the engines.
beforeEach(function () {
    config(['database.connections.empty' => ['driver' => 'sqlite', 'database' => ':memory:'], 'database.default' => 'empty']);
    withConversionsServed();
});

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

it('lists the image formats the command line encodes, tested afresh, or why one is missing', function () {
    // Cached while WebP still encodes: the doctor must probe again, not read this.
    resolve(ImageFormats::class)->available();
    config(['images.default' => 'gd', 'kami.media.avif' => false]);
    failingToEncode('webp');

    Artisan::call('kami:doctor');

    expect(Artisan::output())->toContain('Image formats')
        ->toMatch('/AVIF\W+off \(kami\.media\.avif\)/')
        ->toMatch('/WebP\W+not available/')
        ->toMatch('/JPEG, PNG\W+gd, the fallback/');
});

it('names the driver that encodes a format', function () {
    if (! array_key_exists('webp', resolve(ImageFormats::class)->probe())) {
        $this->markTestSkipped('No Image driver encodes WebP on this host.');
    }

    Artisan::call('kami:doctor');

    expect(Artisan::output())->toMatch('/WebP\W+(gd|imagick) *\n/');
});

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
    config(['cache.default' => 'array']);
    $this->served = Http::response('', 404);

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

    // Laravel's User cannot store an authenticator either.
    expect((new Doctor)->blocking())->toHaveCount(2);
});

it('blocks on a panel whose guard signs in with another model than auth.providers.users.model, naming both', function (?string $users, string $message, string $fix) {
    config([
        'auth.providers.users.model' => $users,
        'auth.providers.editors' => ['driver' => 'eloquent', 'model' => Editor::class],
        'auth.guards.editors' => ['driver' => 'session', 'provider' => 'editors'],
    ]);
    Filament::getPanel('admin')->authGuard('editors');

    $this->artisan('kami:doctor')
        ->expectsOutputToContain("The panel admin signs Users in with Editor, while Kami gives Roles to {$message} (auth.providers.users.model): nobody could sign in to Kami.")
        ->expectsOutputToContain("Fix: {$fix}")
        ->expectsOutputToContain('docs/guide/users-and-roles.md#the-user-model')
        ->assertFailed();

    expect((new Doctor)->blocking())->toHaveCount(1);
})->with([
    'another model' => [User::class, User::class, 'point the provider of the guard editors to ' . User::class . ', or set auth.providers.users.model to Editor.'],
    'no users provider model' => [null, 'no model', 'set auth.providers.users.model to Editor.'],
]);

it('accepts a guard model spelled with another case or a leading backslash, as PHP resolves it', function (string $spelling) {
    config(['auth.providers.users.model' => $spelling]);

    expect((new Doctor)->blocking())->toBeEmpty();
})->with([
    'lower case' => [strtolower(User::class)],
    'leading backslash' => ['\\' . User::class],
]);

it('blocks when a Kami panel no longer requires Kami\'s MFA, set otherwise after the plugin', function (Closure $override) {
    $override(Filament::getPanel('admin'));

    $this->artisan('kami:doctor')
        ->expectsOutputToContain('The panel admin does not require Kami\'s MFA: Users who may manage Users sign in with a password alone.')
        ->expectsOutputToContain('Fix: remove ->multiFactorAuthentication() and ->requiresMultiFactorAuthentication() from the panel admin: KamiPlugin sets them.')
        ->expectsOutputToContain('docs/guide/security.md#mfa')
        ->assertFailed();
})->with([
    'no provider' => [fn (Panel $panel) => $panel->multiFactorAuthentication([])],
    'Filament\'s own provider' => [fn (Panel $panel) => $panel->multiFactorAuthentication(AppAuthentication::make(), isRequired: true)],
    'not required' => [fn (Panel $panel) => $panel->requiresMultiFactorAuthentication(false)],
]);

it('blocks on a panel whose User model cannot store an authenticator, naming what to add', function () {
    config(['auth.providers.users.model' => UserWithoutMfa::class]);

    $this->artisan('kami:doctor')
        ->expectsOutputToContain('The panel admin signs Users in with UserWithoutMfa, which cannot store an authenticator')
        ->expectsOutputToContain('Fix: add implements \\Filament\\Auth\\MultiFactor\\App\\Contracts\\HasAppAuthentication, \\Filament\\Auth\\MultiFactor\\App\\Contracts\\HasAppAuthenticationRecovery to the User model, and use \\Psyao\\Kami\\Admin\\HasKamiMfa; in its body.')
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

it('warns without failing when a host replaced Kami\'s sign-in page with its own', function () {
    Filament::getPanel('admin')->login((new class extends Login {})::class);

    $this->artisan('kami:doctor')
        ->expectsOutputToContain('The panel admin replaces Kami\'s sign-in page with')
        ->expectsOutputToContain('docs/guide/security.md#sign-in')
        ->assertSuccessful();

    expect((new Doctor)->blocking())->toBeEmpty();
});

it('says nothing of a panel that keeps Filament\'s sign-in page, which Kami replaces, or offers none', function (bool $login) {
    Filament::getPanel('admin')->login($login ? Login::class : null);

    $this->artisan('kami:doctor')->doesntExpectOutputToContain('sign-in page');
})->with(['Filament\'s page' => true, 'no sign-in' => false]);

it('warns without failing when a host replaced Kami\'s password reset page with its own', function () {
    Filament::getPanel('admin')->passwordReset((new class extends RequestPasswordReset {})::class);

    $this->artisan('kami:doctor')
        ->expectsOutputToContain('The panel admin replaces Kami\'s password reset page with')
        ->expectsOutputToContain('docs/guide/security.md#password-reset')
        ->assertSuccessful();

    expect((new Doctor)->blocking())->toBeEmpty();
});

it('says nothing of a panel that keeps Filament\'s reset page, which Kami replaces, or offers none', function (bool $reset) {
    Filament::getPanel('admin')->passwordReset($reset ? RequestPasswordReset::class : null);

    $this->artisan('kami:doctor')->doesntExpectOutputToContain('password reset page');
})->with(['Filament\'s page' => true, 'no reset' => false]);

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

it('warns in production, not elsewhere, about an http:// APP_URL or Site URL', function (string $environment, string $key, string $label, bool $warns) {
    config(['app.env' => $environment, 'session.secure' => true, 'app.url' => 'https://example.com', 'kami.sites.default.url' => 'https://example.com']);
    config([$key => 'http://example.com']);

    $doctor = $this->artisan('kami:doctor');
    $warns
        ? $doctor->expectsOutputToContain("{$label} is http://example.com: production travels in clear, the admin's session cookie and passwords included.")
            ->expectsOutputToContain('Fix: serve the site over HTTPS, then start ' . $label . ' with https://.')
            ->expectsOutputToContain('docs/guide/security.md#https-in-production')
            ->assertSuccessful()
        : $doctor->doesntExpectOutputToContain('travels in clear')->assertSuccessful();
})->with([
    'APP_URL in production' => ['production', 'app.url', 'APP_URL', true],
    'a Site URL in production' => ['production', 'kami.sites.default.url', 'kami.sites.default.url', true],
    'APP_URL in local' => ['local', 'app.url', 'APP_URL', false],
    'a Site URL in local' => ['local', 'kami.sites.default.url', 'kami.sites.default.url', false],
]);

it('warns once, upper case included, for a Site URL that is APP_URL, as config/kami.php defaults it', function () {
    config(['app.env' => 'production', 'session.secure' => true, 'app.url' => 'HTTP://example.com', 'kami.sites.default.url' => 'HTTP://example.com']);

    $this->artisan('kami:doctor')
        ->expectsOutputToContain('APP_URL is HTTP://example.com')
        ->doesntExpectOutputToContain('kami.sites.default.url is')
        ->assertSuccessful();
});

it('fails with --strict on each production transport warning, and only then', function (array $config, bool $fails) {
    config(['app.env' => 'production', 'kami.indexable' => true, 'session.secure' => true, 'app.url' => 'https://example.com', 'kami.sites.default.url' => 'https://example.com', 'kami.sites.blog.url' => 'https://blog.example.com', ...$config]);

    $doctor = $this->artisan('kami:doctor', ['--strict' => true]);
    $fails ? $doctor->assertFailed() : $doctor->assertSuccessful();
})->with([
    'all over HTTPS' => [[], false],
    'an http:// APP_URL' => [['app.url' => 'http://example.com', 'kami.sites.default.url' => 'http://example.com'], true],
    'an http:// Site URL' => [['kami.sites.blog.url' => 'http://blog.example.com'], true],
    'a cookie not always Secure' => [['session.secure' => null], true],
]);

it('warns in production, not elsewhere, about a session cookie not always Secure', function (string $environment, ?bool $secure, bool $warns) {
    config(['app.env' => $environment, 'session.secure' => $secure]);

    $doctor = $this->artisan('kami:doctor');
    $warns
        ? $doctor->expectsOutputToContain('The session cookie is not always Secure in production: behind a proxy Laravel does not trust, it travels without it.')
            ->expectsOutputToContain('Fix: set SESSION_SECURE_COOKIE=true in .env.')
            ->expectsOutputToContain('docs/guide/security.md#https-in-production')
            ->assertSuccessful()
        : $doctor->doesntExpectOutputToContain('not always Secure')->assertSuccessful();
})->with([
    'off in production' => ['production', false, true],
    'unset in production' => ['production', null, true],
    'on in production' => ['production', true, false],
    'off in local' => ['local', false, false],
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

describe('Conversions', function () {
    /** @return list<array{0: Severity, 1: string}> */
    function mediaProblems(): array
    {
        return collect(resolve(Doctor::class)->problems())
            ->filter(fn ($problem) => str_starts_with($problem->guide, 'hosting.md#'))
            ->map(fn ($problem) => [$problem->severity, $problem->message])
            ->values()->all();
    }

    it('fails on a missing public/media link, naming kami:install', function () {
        unlink(public_path('media'));

        $problem = collect(resolve(Doctor::class)->problems())->firstWhere('guide', 'hosting.md#conversions');

        expect($problem->severity)->toBe(Severity::Error)
            ->and($problem->message)->toBe('public/media does not link to the public disk\'s media directory (' . Storage::disk('public')->path('media') . '): every Conversion goes through PHP.')
            ->and($problem->fix)->toContain('php artisan kami:install');
        Http::assertNothingSent();
    });

    it('fails on a link to another directory', function () {
        unlink(public_path('media'));
        File::link(temporaryPath(), public_path('media'));

        $problems = mediaProblems();

        expect($problems)->toHaveCount(1)->{0}->{0}->toBe(Severity::Error);
    });

    it('accepts a relative link to the media directory, as a deploy tool may write one', function () {
        unlink(public_path('media'));
        // public/ and the disk's root are siblings under the temporary directory.
        symlink('../' . basename(config('filesystems.disks.public.root')) . '/media', public_path('media'));

        $problems = mediaProblems();

        expect($problems)->toBeEmpty();
    });

    it('fails on a public disk that is not local', function () {
        config(['filesystems.disks.remote' => ['driver' => 's3'], 'kami.media.public_disk' => 'remote']);

        $problems = mediaProblems();

        expect($problems)->toEqual([[Severity::Error, 'kami.media.public_disk is remote, whose driver is s3: Conversions need a local disk, which public/media links to.']]);
        Http::assertNothingSent();
    });

    it('fails on an originals disk the web server can serve, as originals keep their GPS', function (Closure $exposed) {
        $exposed();

        $problems = mediaProblems();

        expect($problems)->toHaveCount(1)->{0}->{0}->toBe(Severity::Error)
            ->and($problems[0][1])->toStartWith('kami.media.originals_disk is ')->toContain('can be served from public/');
    })->with([
        'the public disk' => [fn () => config(['kami.media.originals_disk' => 'public'])],
        'a disk under public/' => [fn () => config(['filesystems.disks.inside' => ['driver' => 'local', 'root' => public_path('uploads')], 'kami.media.originals_disk' => 'inside'])],
        'a disk storage:link exposes' => [function () {
            config(['filesystems.disks.linked' => ['driver' => 'local', 'root' => $root = temporaryPath()], 'kami.media.originals_disk' => 'linked']);
            config(['filesystems.links' => [public_path('storage') => $root]]);
        }],
        'the same directory under another name' => [fn () => config(['filesystems.disks.alias' => ['driver' => 'local', 'root' => config('filesystems.disks.public.root')], 'kami.media.originals_disk' => 'alias'])],
    ]);

    it('accepts a private local disk, and a remote one', function (string $disk) {
        config(['filesystems.disks.remote' => ['driver' => 's3'], 'kami.media.originals_disk' => $disk]);

        $problems = mediaProblems();

        expect($problems)->toBeEmpty();
    })->with(['local', 'remote']);

    it('fails on a lock store each PHP worker keeps to itself, without blocking kami:install', function (string $driver) {
        config(['cache.stores.mine' => ['driver' => $driver], 'cache.default' => 'mine']);

        $problems = mediaProblems();

        expect($problems)->toEqual([[Severity::Error, "The default cache store, mine, uses the {$driver} driver: its locks hold within one PHP worker, so two visitors can generate the same Conversion at once."]])
            ->and(resolve(Doctor::class)->blocking())->toBeEmpty();
    })->with(['array', 'null']);

    it('requests a probe file at the Site\'s URL, and deletes it', function () {
        $problems = mediaProblems();

        expect($problems)->toBeEmpty()
            ->and(Storage::disk('public')->allFiles('media'))->toBe(['media/.htaccess']);
        Http::assertSent(fn (Request $request) => $request->url() === 'http://localhost/media/kami-doctor.svg');
    });

    it('reads Cache-Control\'s directives in any order, as Laravel sends them', function () {
        $this->served = Http::response('<svg/>', 200, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'immutable, max-age=31536000, public',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:",
        ]);

        $problems = mediaProblems();

        expect($problems)->toBeEmpty();
    });

    it('warns about each header the server does not send, as nginx does not without its configuration', function () {
        $this->served = Http::response('<svg/>', 200, ['Cache-Control' => 'max-age=3600']);

        $problems = mediaProblems();

        expect($problems)->toEqual([[Severity::Warning, 'Files under public/media go out without X-Content-Type-Options: nosniff, Cache-Control: public, max-age=31536000, immutable, Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; img-src data:.']]);
    });

    it('only informs about the headers on a developer\'s machine, whose local server, such as Herd\'s nginx, the site never runs on', function (int $status) {
        app()->detectEnvironment(fn () => 'local');
        $this->served = Http::response('<svg/>', $status, ['Cache-Control' => 'max-age=3600']);

        $problems = mediaProblems();

        expect($problems)->toHaveCount(1)->{0}->{0}->toBe(Severity::Info);
    })->with(['headers missing' => 200, 'not served' => 404]);

    it('warns when the probe file is not served', function () {
        $this->served = Http::response('Not Found', 404);

        $problems = mediaProblems();

        expect($problems)->toEqual([[Severity::Warning, 'http://localhost/media/kami-doctor.svg answers 404: the server does not serve public/media.']]);
    });

    it('only informs when the Site sits behind a password', function (int $status) {
        $this->served = Http::response('', $status);

        $problems = mediaProblems();

        expect($problems)->toEqual([[Severity::Info, "The headers of public/media were not checked: http://localhost/media/kami-doctor.svg answers {$status}, behind a password or a firewall."]]);
    })->with([401, 403]);

    it('only informs when the Site\'s URL cannot be reached from here', function () {
        $this->served = fn () => throw new ConnectionException('Could not resolve host');

        $problems = mediaProblems();

        expect($problems)->toEqual([[Severity::Info, 'The headers of public/media were not checked: http://localhost cannot be reached from here (Could not resolve host).']])
            ->and(Storage::disk('public')->allFiles('media'))->toBe(['media/.htaccess']);
    });
});

it('warns when the page cache cannot write to its directory, and only while it is on', function (bool $enabled) {
    $this->app->useStoragePath($storage = temporaryPath());
    File::ensureDirectoryExists("{$storage}/framework");
    chmod("{$storage}/framework", 0555);
    config(['kami.page_cache.enabled' => $enabled]);

    $warning = 'The page cache cannot write to storage/framework/kami/pages/';
    if ($enabled) {
        $this->artisan('kami:doctor')->expectsOutputToContain($warning)->expectsOutputToContain('docs/guide/page-cache.md#storage')->assertSuccessful();
        $this->artisan('kami:doctor', ['--strict' => true])->expectsOutputToContain($warning)->assertFailed();
    } else {
        $this->artisan('kami:doctor', ['--strict' => true])->doesntExpectOutputToContain($warning)->assertSuccessful();
    }

    chmod("{$storage}/framework", 0755);
    File::deleteDirectory($storage);
})->with(['on' => true, 'off' => false]);

describe('search engines', function () {
    beforeEach(function () {
        // Not the skeleton's public/: tests running in parallel would see each other's robots.txt.
        File::ensureDirectoryExists($this->public = temporaryPath());
        $this->app->usePublicPath($this->public);
        config(['kami.indexable' => false]);
    });

    afterEach(function () {
        File::deleteDirectory($this->public);
    });

    /** @return list<array{Severity, string}> */
    function seoProblems(): array
    {
        return collect(resolve(Doctor::class)->problems())
            ->filter(fn ($problem) => str_starts_with($problem->guide, 'seo.md#'))
            ->map(fn ($problem) => [$problem->severity, $problem->message])
            ->values()->all();
    }

    it('warns when the indexable switch contradicts the environment', function (string $env, bool $indexable, array $expected) {
        config(['app.env' => $env, 'kami.indexable' => $indexable]);

        expect(seoProblems())->toBe($expected);
    })->with([
        'production, not indexable' => ['production', false, [[Severity::Warning, 'The Site is not indexable in production: search engines are kept out of it.']]],
        'staging, indexable' => ['staging', true, [[Severity::Warning, 'The Site is indexable outside production (staging): search engines may index this copy.']]],
        'production, indexable' => ['production', true, []],
        'local, not indexable' => ['local', false, []],
    ]);

    it('warns when the managed robots.txt differs from what kami:install writes', function () {
        File::put("{$this->public}/robots.txt", "# Managed by Kami (kami:install). Delete this line to own this file.\nUser-agent: *\nDisallow: /\n");
        expect(seoProblems())->toBe([]);

        File::append("{$this->public}/robots.txt", "Disallow: /drafts\n");
        expect(seoProblems())->toBe([[Severity::Warning, 'public/robots.txt differs from what kami:install writes.']]);

        // Not its exit code: public/media is missing from this public/.
        $this->artisan('kami:doctor')->expectsOutputToContain('Fix: run kami:install, or delete its first line to own the file.')->expectsOutputToContain('docs/guide/seo.md#robotstxt');
    });

    it('warns when the site\'s own robots.txt names no sitemap, only while the Site is indexable', function () {
        config(['kami.indexable' => true, 'app.env' => 'production']);
        File::put("{$this->public}/robots.txt", "User-agent: *\nDisallow: /admin\n");
        expect(seoProblems())->toBe([[Severity::Warning, 'public/robots.txt, the site\'s own, names no sitemap.']]);

        File::append("{$this->public}/robots.txt", "sitemap: http://localhost/sitemap.xml\n");
        expect(seoProblems())->toBe([]);

        File::put("{$this->public}/robots.txt", "User-agent: *\nDisallow: /\n");
        config(['kami.indexable' => false, 'app.env' => 'local']);
        expect(seoProblems())->toBe([]);
    });

    it('warns when a route of the application answers an address Kami writes a file at', function (string $path) {
        Route::get($path, fn () => '');
        Kami::routes();

        expect(seoProblems())->toBe([[Severity::Warning, "A route of the application answers /{$path}: Kami writes no public/{$path}, as the file would shadow the route."]]);
    })->with(['robots.txt', 'sitemap.xml']);

    it('counts neither Kami\'s fallback nor a missing route as a route of the application', function () {
        Kami::routes();

        expect(seoProblems())->toBe([]);
    });
});
