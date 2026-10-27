<?php

namespace Psyao\Kami\Tests\Install;

use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Testbench's bare skeleton, with no panel: an application Kami has not been added to yet.
 * The suite runs serially, as `kami:install` publishes assets into the skeleton's shared `public/`.
 */
abstract class InstallTestCase extends Orchestra
{
    /** Load Filament and Kami the way a real Site does: through package discovery. */
    protected $enablesPackageDiscoveries = true;

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
