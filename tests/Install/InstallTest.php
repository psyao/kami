<?php

use Filament\Panel;
use Filament\PanelRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Psyao\Kami\Support\Internal\Doctor\Doctor;
use Workbench\App\Providers\AdminPanelProvider;

/** The host adds Kami to its own panel, as the Workbench's provider does. */
function addKamiToAPanel(): void
{
    app(PanelRegistry::class)->register((new AdminPanelProvider(app()))->panel(Panel::make()));
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
    addKamiToAPanel();

    $this->artisan('kami:install', ['--no-interaction' => true])->assertSuccessful();

    expect(Schema::hasTable('migrations'))->toBeTrue()
        ->and(public_path('css/psyao/kami/kami.css'))->toBeFile()
        ->and(public_path('js/filament/filament/app.js'))->toBeFile();
});

it('refuses, with the lines to add, while no panel carries KamiPlugin', function () {
    $this->artisan('kami:install', ['--no-interaction' => true])
        ->expectsOutputToContain('use Psyao\Kami\Admin\KamiPlugin;')
        ->expectsOutputToContain('->plugin(KamiPlugin::make())')
        ->assertFailed();

    expect(Schema::hasTable('migrations'))->toBeFalse()
        ->and(public_path('js'))->not->toBeDirectory();
});

it('fails before migrating on a blocking environment error', function () {
    addKamiToAPanel();
    $this->app->instance(Doctor::class, new Doctor(phpVersion: '8.4.13'));

    $this->artisan('kami:install', ['--no-interaction' => true])
        ->expectsOutputToContain('PHP 8.4.13 is below Kami\'s floor, 8.5.0')
        ->expectsOutputToContain('docs/guide/installation.md#requirements')
        ->assertFailed();

    expect(Schema::hasTable('migrations'))->toBeFalse()
        ->and(public_path('js'))->not->toBeDirectory();
});

it('changes nothing when run again', function () {
    addKamiToAPanel();
    $this->artisan('kami:install', ['--no-interaction' => true])->assertSuccessful();
    $files = skeletonFiles();
    $migrations = DB::table('migrations')->get();

    $this->artisan('kami:install', ['--no-interaction' => true])->assertSuccessful();

    expect(skeletonFiles())->toBe($files)
        ->and(DB::table('migrations')->get())->toEqual($migrations);
});

it('writes no file of the host\'s, only Filament\'s public assets', function () {
    addKamiToAPanel();
    $before = skeletonFiles();

    $this->artisan('kami:install', ['--no-interaction' => true])->assertSuccessful();

    $after = skeletonFiles();
    $added = array_keys(array_diff_key($after, $before));
    expect(array_intersect_key($after, $before))->toBe($before)
        ->and($added)->not->toBeEmpty()
        ->and(array_filter($added, fn (string $path) => ! str_starts_with($path, 'public/')))->toBeEmpty();
});

it('has no kami:upgrade: kami:install serves every deploy', function () {
    expect(Artisan::all())->toHaveKey('kami:install')->not->toHaveKey('kami:upgrade');
});
