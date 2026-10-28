<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin;

use Filament\AvatarProviders\UiAvatarsProvider;
use Filament\Contracts\Plugin;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Filament\Resources\ResourceConfiguration;
use Filament\Support\Assets\Css;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Config;
use LogicException;
use Psyao\Kami\Admin\Internal\EntryResource;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Support\Internal\Install\PluginRegistration;

/** Kami's admin, added by the host to its own panel with `->plugin(KamiPlugin::make())`. */
final class KamiPlugin implements Plugin
{
    public static function make(): self
    {
        return new self;
    }

    public function getId(): string
    {
        return 'kami';
    }

    public function register(Panel $panel): void
    {
        $registration = resolve(PluginRegistration::class);
        $registration->onAPanel = true;
        $registration->panels[spl_object_id($panel)] = fn (): array => [
            'id' => $panel->getId(),
            'guard' => $panel->getAuthGuard(),
            'strict' => $panel->isAuthorizationStrict(),
            // Filament's default sends Users' initials to ui-avatars.com; the doctor warns, since the setting is the host's (ADR 0023).
            'remoteAvatars' => is_a($panel->getDefaultAvatarProvider(), UiAvatarsProvider::class, true),
            // Filament's own rule (its `Authenticate` middleware): a User that is not a FilamentUser gets in locally only.
            'admits' => fn (Authenticatable $user): bool => $user instanceof FilamentUser ? $user->canAccessPanel($panel) : config('app.env') === 'local',
            // A panel does not run the `web` group, so it needs `SecurityHeaders` of its own (ADR 0018).
            'middleware' => array_values(array_filter($panel->getMiddleware(), is_string(...))),
        ];

        // One registration per declared type, each with its own URL, navigation item and Permissions; the class comes from config, the door for overridable Resources in V1 (ADR 0029).
        $resource = Config::string('kami.resources.entry', EntryResource::class);

        if (! is_a($resource, EntryResource::class, true)) {
            throw new LogicException('kami.resources.entry names ' . EntryResource::class . ' or a subclass.');
        }

        // Added twice to one panel, the plugin registers its types once.
        foreach (resolve(ContentTypes::class)->all() as $type) {
            if (! $panel->getResourceConfiguration($resource, $type->handle()) instanceof ResourceConfiguration) {
                $panel->resources([$resource::configure($type)]);
            }
        }

        // Precompiled, so a site needs neither Node nor a custom theme for Kami's admin (ADR 0023).
        $panel->assets([Css::make('kami', __DIR__ . '/../../resources/dist/kami.css')], 'psyao/kami');
    }

    public function boot(Panel $panel): void {}
}
