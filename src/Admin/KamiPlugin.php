<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin;

use Filament\AvatarProviders\UiAvatarsProvider;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Assets\Css;
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
        // Filament's default sends Users' initials to ui-avatars.com; the doctor warns, since the setting is the host's (ADR 0023).
        $registration->remoteAvatarChecks[spl_object_id($panel)] = fn (): ?string => is_a($panel->getDefaultAvatarProvider(), UiAvatarsProvider::class, true) ? $panel->getId() : null;

        // Precompiled, so a site needs neither Node nor a custom theme for Kami's admin (ADR 0023).
        $panel->assets([Css::make('kami', __DIR__ . '/../../resources/dist/kami.css')], 'psyao/kami');
    }

    public function boot(Panel $panel): void {}
}
