<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Psyao\Kami\Support\Internal\Doctor\Doctor;

it('finds no problem in a sound environment', function () {
    $this->artisan('kami:doctor')
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
    $this->app->instance(Doctor::class, new Doctor(extensionLoaded: fn (string $extension) => $extension !== 'intl'));

    $this->artisan('kami:doctor')
        ->expectsOutputToContain('The PHP extension intl is not loaded')
        ->expectsOutputToContain('docs/guide/installation.md#requirements')
        ->assertFailed();
});

it('warns without failing when no image extension is loaded', function () {
    $this->app->instance(Doctor::class, new Doctor(extensionLoaded: fn (string $extension) => ! in_array($extension, ['gd', 'imagick'], true)));

    $this->artisan('kami:doctor')
        ->expectsOutputToContain('Neither gd nor imagick is loaded')
        ->assertSuccessful();
});

it('fails on a warning with --strict', function () {
    $this->app->instance(Doctor::class, new Doctor(extensionLoaded: fn (string $extension) => ! in_array($extension, ['gd', 'imagick'], true)));

    $this->artisan('kami:doctor', ['--strict' => true])
        ->expectsOutputToContain('Neither gd nor imagick is loaded')
        ->assertFailed();
});

it('fails on a directory Kami cannot write to', function (string $directory) {
    $missing = sys_get_temp_dir() . '/kami-doctor-' . uniqid() . '/missing';
    match ($directory) {
        'storage' => $this->app->useStoragePath($missing),
        'bootstrap/cache' => $this->app->useBootstrapPath($missing),
    };

    $this->artisan('kami:doctor')
        ->expectsOutputToContain('is not writable')
        ->expectsOutputToContain('docs/guide/installation.md#writable-directories')
        ->assertFailed();
})->with(['storage', 'bootstrap/cache']);

it('fails when the database cannot be reached', function () {
    config(['database.connections.testing.database' => sys_get_temp_dir() . '/kami-doctor-' . uniqid() . '/missing.sqlite']);
    DB::purge('testing');

    $this->artisan('kami:doctor')
        ->expectsOutputToContain('Kami cannot connect to the database testing')
        ->expectsOutputToContain('docs/guide/installation.md#database')
        ->assertFailed();
});

it('writes its last result under Kami\'s storage directory', function () {
    $this->app->instance(Doctor::class, new Doctor(extensionLoaded: fn (string $extension) => ! in_array($extension, ['gd', 'imagick'], true)));
    // A storage of its own: parallel processes share the skeleton's.
    $this->app->useStoragePath($storage = sys_get_temp_dir() . '/kami-doctor-' . uniqid());
    File::ensureDirectoryExists($storage);

    $this->artisan('kami:doctor')->assertSuccessful();

    $result = File::json("{$storage}/framework/kami/doctor.json");
    expect($result['checked_at'])->toBeString()
        ->and($result['problems'])->toHaveCount(1)
        ->and($result['problems'][0])->toMatchArray(['severity' => 'warning', 'guide' => 'installation.md#requirements']);

    File::deleteDirectory($storage);
});

it('offers its environment errors alone, for kami:install to block on', function () {
    $doctor = new Doctor(extensionLoaded: fn (string $extension) => ! in_array($extension, ['intl', 'gd', 'imagick'], true));

    expect($doctor->blocking())->toHaveCount(1)
        ->and($doctor->blocking()[0]->message)->toBe('The PHP extension intl is not loaded.');
});

it('links every problem to a section of the guide', function () {
    $this->app->useStoragePath(sys_get_temp_dir() . '/kami-doctor-' . uniqid() . '/missing');
    config(['database.connections.testing.database' => sys_get_temp_dir() . '/kami-doctor-' . uniqid() . '/missing.sqlite']);
    DB::purge('testing');
    $everything = new Doctor(phpVersion: '8.4.13', extensionLoaded: fn () => false);

    foreach ($everything->problems() as $problem) {
        [$page, $anchor] = explode('#', $problem->guide);
        $headings = Str::of(File::get(dirname(__DIR__) . "/docs/guide/{$page}"))
            ->matchAll('/^#+ (.+)$/m')
            ->map(fn (string $heading) => Str::slug($heading));

        expect($headings)->toContain($anchor);
    }
});
