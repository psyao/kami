<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Psyao\Kami\Content\Fields\Text;

// Here rather than beside the other kami:make:type tests: it runs kami:install, which publishes into the shared skeleton.
it('passes kami:install and its drift check once the printed steps are followed', function () {
    $this->bootWithKamiOnAPanel();
    $site = sys_get_temp_dir() . '/kami-make-type-' . uniqid();
    $this->app->useAppPath("{$site}/app");
    $this->app->useDatabasePath("{$site}/database");
    File::ensureDirectoryExists(database_path('migrations'));
    $install = ['kami:install', ['--email' => 'admin@example.test', '--name' => 'Admin', '--no-interaction' => true]];

    try {
        $this->artisan(...$install)->assertSuccessful();
        $this->artisan('kami:make:type', ['name' => 'MadeGadget', '--singleton' => true])->assertSuccessful();

        $path = app_path('Content/MadeGadget.php');
        File::put($path, str_replace('return [];', 'return [\\' . Text::class . "::make('tagline')];", File::get($path)));
        require_once $path;
        config(['kami.types' => [$this->app->getNamespace() . 'Content\MadeGadget']]);

        $this->artisan('kami:make:migration', ['type' => 'made_gadget', '--no-interaction' => true])->assertSuccessful();
        $this->artisan(...$install)->assertSuccessful();

        $this->assertDatabaseHas('kami_entries', ['type' => 'made_gadget']);
        expect(Schema::hasColumn('kami_type_made_gadget', 'tagline'))->toBeTrue();
    } finally {
        File::deleteDirectory($site);
    }
});
