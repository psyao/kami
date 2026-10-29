<?php

declare(strict_types=1);

namespace Psyao\Kami\Tests\Install;

use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase as Orchestra;
use Workbench\App\Models\User;
use Workbench\App\Providers\AdminPanelProvider;

use function Orchestra\Testbench\default_migration_path;

/**
 * Testbench's bare skeleton, with no panel: an application Kami has not been added to yet.
 * The suite runs serially, as `kami:install` publishes assets into the skeleton's shared `public/`.
 */
abstract class InstallTestCase extends Orchestra
{
    /** Load Filament and Kami the way a real Site does: through package discovery. */
    protected $enablesPackageDiscoveries = true;

    /** @var list<class-string> */
    private array $panelProviders = [];

    /** Boots again with the Workbench's panel provider and User model, so Kami reaches a panel the way it does in a site. */
    public function bootWithKamiOnAPanel(): void
    {
        $this->panelProviders = [AdminPanelProvider::class];
        $this->refreshApplication();
        config(['auth.providers.users.model' => User::class]);
        // A site has its users table: Laravel's migrations run with Kami's when kami:install migrates.
        $this->app->make('migrator')->path(default_migration_path());
    }

    protected function getPackageProviders($app): array
    {
        return $this->panelProviders;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The skeleton ships `public/` without a directory: anything there was published by an earlier run.
        File::deleteDirectories(public_path());
        // What a deploy script exports for the first administrator; a test about its absence unsets it.
        $_SERVER['KAMI_ADMIN_PASSWORD'] = 'correct horse battery';
    }

    protected function tearDown(): void
    {
        File::deleteDirectories(public_path());
        unset($_SERVER['KAMI_ADMIN_PASSWORD']);

        parent::tearDown();
    }
}
