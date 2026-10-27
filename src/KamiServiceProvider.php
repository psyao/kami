<?php

namespace Psyao\Kami;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/** Registers Kami's core: config, migrations, views and translations, and nothing global beyond UTC. */
final class KamiServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('kami')
            ->hasConfigFile()
            ->hasViews()
            ->hasTranslations();
    }

    public function packageRegistered(): void
    {
        // Dates are stored and computed in UTC whatever the host's time zone; each Site displays its own (ADR 0004).
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
    }

    public function packageBooted(): void
    {
        // Run from the package, never published: a copy in the site would drift from the append-only history (ADR 0035).
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
