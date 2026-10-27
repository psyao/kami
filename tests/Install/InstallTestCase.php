<?php

namespace Psyao\Kami\Tests\Install;

use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase as Orchestra;
use Workbench\App\Providers\AdminPanelProvider;

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

    /** Boots again with the Workbench's panel provider, so Kami reaches a panel the way it does in a site. */
    public function bootWithKamiOnAPanel(): void
    {
        $this->panelProviders = [AdminPanelProvider::class];
        $this->refreshApplication();
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
    }

    protected function tearDown(): void
    {
        File::deleteDirectories(public_path());

        parent::tearDown();
    }
}
