<?php

declare(strict_types=1);

namespace Psyao\Kami\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use WithWorkbench;

    /** Load Filament and Kami the way a real Site does: through package discovery. */
    protected $enablesPackageDiscoveries = true;

    /** @var list<string> The databases this process created, once each. */
    private static array $databases = [];

    protected function defineEnvironment($app): void
    {
        // A lazy load, a discarded or a missing Eloquent attribute throws.
        Model::shouldBeStrict();

        $this->useOwnDatabaseInParallel($app);
    }

    /**
     * Paratest's processes would share one MySQL or PostgreSQL database: each gets its own, as Laravel's parallel testing gives an
     * application's. Laravel's own switch (`LARAVEL_PARALLEL_TESTING`) also makes Testbench look for a package manifest per process.
     */
    private function useOwnDatabaseInParallel(Application $app): void
    {
        $token = $_SERVER['TEST_TOKEN'] ?? null;
        $config = $app->make('config');
        $connection = $config->string('database.default');

        if (! is_string($token) || $config->get("database.connections.{$connection}.driver") === 'sqlite') {
            return;
        }

        $database = $config->string("database.connections.{$connection}.database") . "_test_{$token}";

        if (! in_array($database, self::$databases, true)) {
            $schema = $app->make('db')->connection($connection)->getSchemaBuilder();
            $schema->dropDatabaseIfExists($database);
            $schema->createDatabase($database);
            $app->make('db')->purge($connection);
            self::$databases[] = $database;
        }

        $config->set("database.connections.{$connection}.database", $database);
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
