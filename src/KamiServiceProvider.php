<?php

declare(strict_types=1);

namespace Psyao\Kami;

use Closure;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Image\ImageManager;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Psyao\Kami\Audit\Actions\RecordAuditEvent;
use Psyao\Kami\Audit\Internal\PruneAuditCommand;
use Psyao\Kami\Content\Events\EntryDeleted;
use Psyao\Kami\Content\Events\EntryDuplicated;
use Psyao\Kami\Content\Events\EntryRestored;
use Psyao\Kami\Content\Events\EntrySaved;
use Psyao\Kami\Content\Events\EntryTrashed;
use Psyao\Kami\Content\Events\TranslationDeleted;
use Psyao\Kami\Content\Internal\AnnouncePublicationsCommand;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Content\Internal\MakeMigrationCommand;
use Psyao\Kami\Content\Internal\MakeTypeCommand;
use Psyao\Kami\Forms\Internal\Deliveries;
use Psyao\Kami\Forms\Internal\DeliverSubmissionsCommand;
use Psyao\Kami\Forms\Internal\Forms;
use Psyao\Kami\Forms\Internal\MakeFormCommand;
use Psyao\Kami\Forms\Internal\PruneSubmissionsCommand;
use Psyao\Kami\Media\Events\MediaDeleted;
use Psyao\Kami\Media\Events\MediaSaved;
use Psyao\Kami\Media\Internal\ImagePresets;
use Psyao\Kami\Media\Internal\PruneMediaCommand;
use Psyao\Kami\Media\Internal\StripMetadata;
use Psyao\Kami\Media\Internal\WarmMediaCommand;
use Psyao\Kami\Routing\Internal\BenchCommand;
use Psyao\Kami\Routing\Internal\CachePages;
use Psyao\Kami\Routing\Internal\ClearCacheCommand;
use Psyao\Kami\Routing\Internal\PageCache;
use Psyao\Kami\Scheduling\Internal\Tick;
use Psyao\Kami\Scheduling\Internal\TickCommand;
use Psyao\Kami\Scheduling\Internal\TickOnVisit;
use Psyao\Kami\Scheduling\Internal\TickUrlCommand;
use Psyao\Kami\Seo\Internal\Sitemap;
use Psyao\Kami\Seo\Internal\SitemapCommand;
use Psyao\Kami\Sites\Internal\ResolveLocale;
use Psyao\Kami\Support\Internal\Doctor\DoctorCommand;
use Psyao\Kami\Support\Internal\FrontSecurityHeaders;
use Psyao\Kami\Support\Internal\Install\InstallCommand;
use Psyao\Kami\Support\Internal\Install\PluginRegistration;
use Psyao\Kami\Support\SecurityHeaders;
use Psyao\Kami\Users\Internal\GrantRoleCommand;
use Psyao\Kami\Users\Internal\Permissions;
use Psyao\Kami\Users\Internal\ResetMfaCommand;
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
            ->hasCommands([AnnouncePublicationsCommand::class, BenchCommand::class, ClearCacheCommand::class, DeliverSubmissionsCommand::class, DoctorCommand::class, GrantRoleCommand::class, InstallCommand::class, MakeFormCommand::class, MakeMigrationCommand::class, MakeTypeCommand::class, PruneAuditCommand::class, PruneMediaCommand::class, PruneSubmissionsCommand::class, ResetMfaCommand::class, SitemapCommand::class, TickCommand::class, TickUrlCommand::class, WarmMediaCommand::class]);
    }

    public function packageRegistered(): void
    {
        $this->optimizes(clear: 'kami:cache:clear');
        $this->app->singleton(PluginRegistration::class);
        $this->app->singleton(ContentTypes::class);
        $this->app->singleton(Forms::class);
        $this->app->singleton(ImagePresets::class);
        // The Admin language, as configured: a visitor's request switches `app.locale` to its page's Locale.
        $this->app->instance(Deliveries::ADMIN_LANGUAGE, config('app.locale'));

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
        $router->middlewareGroup('kami', [TickOnVisit::class, CachePages::class, FrontSecurityHeaders::class, ResolveLocale::class]);
        $router->aliasMiddleware('kami.security-headers', SecurityHeaders::class);

        // Every public write action dispatches one of these after commit (ADR 0029); `EntrySaved` comes with each `EntryPublished` and `EntryUnpublished`.
        Event::listen([EntrySaved::class, EntryDuplicated::class, EntryTrashed::class, EntryRestored::class, EntryDeleted::class, TranslationDeleted::class, MediaSaved::class, MediaDeleted::class], PageCache::invalidate(...));
        // Any save changes a Translation's `updated_at`, the sitemap's `lastmod`, beside publishing, Paths and `noindex` (#410); a type with no URL is in no sitemap (#446).
        Event::listen(EntrySaved::class, fn (EntrySaved $event) => $event->entry->contentType()?->isRoutable() === true ? Sitemap::markStale() : null);
        Event::listen([EntryTrashed::class, EntryRestored::class, EntryDeleted::class, TranslationDeleted::class], fn () => Sitemap::markStale());
        // Every run of a Scheduled task, the Tick's and a native `schedule:run`'s, feeds the overdue rule (ADR 0020).
        Event::listen(ScheduledTaskStarting::class, Tick::starting(...));
        Event::listen(ScheduledTaskFinished::class, Tick::finished(...));
        Event::listen(ScheduledTaskFailed::class, Tick::failed(...));

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

        // The event's Kami User when it signs in on a Kami panel's guard; another guard, a front's for instance, is the host's own.
        $kamiUser = fn (Login | Failed $event): ?Model => $event->user instanceof KamiUser && $event->user instanceof Model
            && array_any(resolve(PluginRegistration::class)->guards, fn (Closure $guard): bool => $guard() === $event->guard) ? $event->user : null;

        Event::listen(Login::class, function (Login $event) use ($kamiUser): void {
            if (($user = $kamiUser($event)) instanceof Model) {
                resolve(RecordAuditEvent::class)->handle('kami.auth.signed-in', $user, actor: $event->user);
            }
        });

        // An existing account only, with no actor: whoever typed the password is unknown. An unknown address records nothing, and the credentials never.
        Event::listen(Failed::class, function (Failed $event) use ($kamiUser): void {
            if (($user = $kamiUser($event)) instanceof Model) {
                resolve(RecordAuditEvent::class)->handle('kami.auth.sign-in-failed', $user);
            }
        });

        // Laravel's event carries no guard: the core records the lockouts Kami's sign-in page fires, which name the throttled key's kind, never the email.
        Event::listen(Lockout::class, function (Lockout $event): void {
            if (is_string($key = $event->request->attributes->get('kami.throttled'))) {
                resolve(RecordAuditEvent::class)->handle('kami.auth.locked-out', context: ['key' => $key]);
            }
        });
    }
}
