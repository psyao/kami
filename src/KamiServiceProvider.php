<?php

declare(strict_types=1);

namespace Psyao\Kami;

use Illuminate\Contracts\Auth\Access\Gate;
use Psyao\Kami\Support\Internal\Doctor\DoctorCommand;
use Psyao\Kami\Support\Internal\Install\InstallCommand;
use Psyao\Kami\Support\Internal\Install\PluginRegistration;
use Psyao\Kami\Users\Internal\Permissions;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/** Registers Kami's core: config, migrations and commands, and nothing global beyond UTC. */
final class KamiServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        // ponytail: no hasViews() or hasTranslations() until resources/views or resources/lang exists, or `view:cache` fails (#65).
        $package->name('kami')
            ->hasConfigFile()
            ->hasCommands([DoctorCommand::class, InstallCommand::class]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(PluginRegistration::class);

        // Dates are stored and computed in UTC whatever the host's time zone; each Site displays its own (ADR 0004).
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
    }

    public function packageBooted(): void
    {
        // Run from the package, never published: a copy in the site would drift from the append-only history (ADR 0035).
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Once something asks the Gate, so a front request that never does instantiates no Content Type for it.
        $this->callAfterResolving(Gate::class, Permissions::register(...));
    }
}
