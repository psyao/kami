<?php

declare(strict_types=1);

namespace Psyao\Kami\Support\Internal\Install;

use Closure;

/**
 * What `KamiPlugin` reports about the host panels it sits on: the core never imports Filament, the plugin hands it booleans and callbacks (ADR 0011).
 *
 * @internal
 */
final class PluginRegistration
{
    public bool $onAPanel = false;

    /**
     * Keyed by panel object, one per panel carrying `KamiPlugin`: its id when it still draws avatars with Filament's `UiAvatarsProvider`, null otherwise.
     * Asked when the doctor runs, since the host may set the provider after `->plugin()`.
     *
     * @var array<int, Closure(): ?string>
     */
    public array $remoteAvatarChecks = [];
}
