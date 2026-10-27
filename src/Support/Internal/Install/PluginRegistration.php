<?php

declare(strict_types=1);

namespace Psyao\Kami\Support\Internal\Install;

use Closure;

/**
 * Whether a host panel carries `KamiPlugin`, as the plugin itself reports it: the core never asks Filament (ADR 0011).
 *
 * @internal
 */
final class PluginRegistration
{
    public bool $onAPanel = false;

    /**
     * One per panel carrying `KamiPlugin`: its id when it still draws avatars with Filament's `UiAvatarsProvider`, null otherwise.
     * Asked when the doctor runs, since the host may set the provider after `->plugin()`.
     *
     * @var list<Closure(): ?string>
     */
    public array $remoteAvatarPanels = [];
}
