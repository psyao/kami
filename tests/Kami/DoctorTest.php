<?php

declare(strict_types=1);

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\AvatarProviders\UiAvatarsProvider;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Psyao\Kami\Admin\InitialsAvatarProvider;
use Psyao\Kami\Admin\KamiPlugin;
use Psyao\Kami\Support\Internal\Doctor\Doctor;

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
    Panel::make()->id('remote')->plugin(KamiPlugin::make())->plugin(KamiPlugin::make());

    expect((new Doctor)->problems())->toHaveCount(1);
});

it('accepts a Kami panel with a local avatar provider, even set after the plugin', function (string $provider) {
    Panel::make()->id('local')->plugin(KamiPlugin::make())->defaultAvatarProvider($provider);

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
