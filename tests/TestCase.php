<?php

namespace Psyao\Kami\Tests;

use Illuminate\Database\Eloquent\Model;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use WithWorkbench;

    /** Load Filament and Kami the way a real Site does: through package discovery. */
    protected $enablesPackageDiscoveries = true;

    protected function defineEnvironment($app): void
    {
        // A lazy load, a discarded or a missing Eloquent attribute throws.
        Model::shouldBeStrict();
    }

    /** The host runs in a Swiss time zone, as Infomaniak and Kami's sites do, set before any provider registers: Kami must still compute in UTC. */
    protected function resolveApplicationConfiguration($app): void
    {
        ini_set('date.timezone', 'Europe/Zurich');
        parent::resolveApplicationConfiguration($app);
        $app['config']->set('app.timezone', 'Europe/Zurich');
        date_default_timezone_set('Europe/Zurich');
    }
}
