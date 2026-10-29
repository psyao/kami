<?php

declare(strict_types=1);

namespace Psyao\Kami;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Routing\Router;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Content\Internal\MakeMigrationCommand;
use Psyao\Kami\Sites\Internal\ResolveLocale;
use Psyao\Kami\Support\Internal\Doctor\DoctorCommand;
use Psyao\Kami\Support\Internal\FrontSecurityHeaders;
use Psyao\Kami\Support\Internal\Install\InstallCommand;
use Psyao\Kami\Support\Internal\Install\PluginRegistration;
use Psyao\Kami\Support\SecurityHeaders;
use Psyao\Kami\Users\Internal\Permissions;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/** Registers Kami's core: config, translations, migrations and commands, and nothing global beyond UTC. */
final class KamiServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        // ponytail: no hasViews() until resources/views exists, or `view:cache` fails (#65).
        $package->name('kami')
            ->hasConfigFile()
            ->hasTranslations()
            ->hasCommands([DoctorCommand::class, InstallCommand::class, MakeMigrationCommand::class]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(PluginRegistration::class);
        $this->app->singleton(ContentTypes::class);

        // Dates are stored and computed in UTC whatever the host's time zone; each Site displays its own (ADR 0004).
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
    }

    public function packageBooted(): void
    {
        // Run from the package, never published: a copy in the site would drift from the append-only history (ADR 0035).
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Names only, inert until the host calls `Kami::routes()` or adds the alias to its panel; declared here, not with the route, because `route:cache` stores neither.
        $router = $this->app->make(Router::class);
        $router->middlewareGroup('kami', [FrontSecurityHeaders::class, ResolveLocale::class]);
        $router->aliasMiddleware('kami.security-headers', SecurityHeaders::class);

        // Once something asks the Gate, so a front request that never does instantiates no Content Type for it.
        $this->callAfterResolving(Gate::class, Permissions::register(...));
    }
}
