<?php

declare(strict_types=1);

namespace Psyao\Kami;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Image\ImageManager;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Psyao\Kami\Audit\Actions\RecordAuditEvent;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Content\Internal\MakeMigrationCommand;
use Psyao\Kami\Content\Internal\MakeTypeCommand;
use Psyao\Kami\Media\Internal\ImagePresets;
use Psyao\Kami\Media\Internal\PruneMediaCommand;
use Psyao\Kami\Media\Internal\StripMetadata;
use Psyao\Kami\Media\Internal\WarmMediaCommand;
use Psyao\Kami\Sites\Internal\ResolveLocale;
use Psyao\Kami\Support\Internal\Doctor\DoctorCommand;
use Psyao\Kami\Support\Internal\FrontSecurityHeaders;
use Psyao\Kami\Support\Internal\Install\InstallCommand;
use Psyao\Kami\Support\Internal\Install\PluginRegistration;
use Psyao\Kami\Support\SecurityHeaders;
use Psyao\Kami\Users\Internal\Permissions;
use Psyao\Kami\Users\KamiUser;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/** Registers Kami's core: config, translations, migrations and commands, and nothing global beyond UTC. */
final class KamiServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('kami')
            ->hasConfigFile()
            ->hasTranslations()
            ->hasViews()
            // ponytail: `kami:media:prune` runs when an operator starts it; M8's Tick schedules it weekly.
            ->hasCommands([DoctorCommand::class, InstallCommand::class, MakeMigrationCommand::class, MakeTypeCommand::class, PruneMediaCommand::class, WarmMediaCommand::class]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(PluginRegistration::class);
        $this->app->singleton(ContentTypes::class);
        $this->app->singleton(ImagePresets::class);

        // Dates are stored and computed in UTC whatever the host's time zone; each Site displays its own (ADR 0004).
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
    }

    public function packageBooted(): void
    {
        // `mergeConfigFrom()` merges the top level only: a site's `media` block with `presets` alone, as the guide shows it, keeps Kami's other keys.
        if (! $this->app->configurationIsCached()) {
            /** @var array{media: array<string, mixed>} $defaults */
            $defaults = require __DIR__ . '/../config/kami.php';
            config(['kami.media' => [...$defaults['media'], ...(array) config('kami.media')]]);
        }

        // Run from the package, never published: a copy in the site would drift from the append-only history (ADR 0035).
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Names only, inert until the host calls `Kami::routes()` or adds the alias to its panel; declared here, not with the route, because `route:cache` stores neither.
        $router = $this->app->make(Router::class);
        $router->middlewareGroup('kami', [FrontSecurityHeaders::class, ResolveLocale::class]);
        $router->aliasMiddleware('kami.security-headers', SecurityHeaders::class);

        // Once something asks the Gate, so a front request that never does instantiates no Content Type for it.
        $this->callAfterResolving(Gate::class, Permissions::register(...));
        $this->callAfterResolving('image', function (ImageManager $images): void {
            $images->transformUsing('gd', StripMetadata::class, StripMetadata::handle(...));
            $images->transformUsing('imagick', StripMetadata::class, StripMetadata::handle(...));
        });

        // Laravel's event carries no guard: a KamiUser is the User a Kami panel signs in, as the doctor requires.
        Event::listen(PasswordReset::class, function (PasswordReset $event): void {
            if ($event->user instanceof KamiUser && $event->user instanceof Model) {
                resolve(RecordAuditEvent::class)->handle('kami.auth.password-reset', $event->user, actor: $event->user);
            }
        });
    }
}
