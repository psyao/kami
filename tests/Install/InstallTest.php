<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Psyao\Kami\Support\Internal\Doctor\Doctor;

function install(): PendingCommand
{
    return test()->artisan('kami:install', ['--no-interaction' => true]);
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
        ->and(public_path('js/filament/filament/app.js'))->toBeFile();
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
    'unreachable database' => [function () {
        config(['database.connections.testing.database' => sys_get_temp_dir() . '/kami-install-' . uniqid() . '/missing.sqlite']);
        DB::purge('testing');
    }, 'Kami cannot connect to the database testing'],
]);

it('changes nothing when run again', function () {
    $this->bootWithKamiOnAPanel();
    install()->assertSuccessful();
    $files = skeletonFiles();
    $migrations = DB::table('migrations')->get();

    install()->assertSuccessful();

    expect(skeletonFiles())->toBe($files)
        ->and(DB::table('migrations')->get())->toEqual($migrations);
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

it('has no kami:upgrade: kami:install serves every deploy', function () {
    expect(Artisan::all())->toHaveKey('kami:install')->not->toHaveKey('kami:upgrade');
});
