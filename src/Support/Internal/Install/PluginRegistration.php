<?php

declare(strict_types=1);

namespace Psyao\Kami\Support\Internal\Install;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * What `KamiPlugin` reports about the host panels it sits on: the core never imports Filament, the plugin hands it booleans and callbacks (ADR 0011).
 *
 * @internal
 */
final class PluginRegistration
{
    public bool $onAPanel = false;

    /**
     * Keyed by panel object, one per panel carrying `KamiPlugin`, asked when the doctor runs, since the host may configure the panel after `->plugin()`.
     * `remoteAvatars` when it still draws avatars with Filament's `UiAvatarsProvider`; `admits` answers as Filament does when a User signs in.
     *
     * @var array<int, Closure(): array{id: string, guard: string, strict: bool, remoteAvatars: bool, admits: Closure(Authenticatable): bool}>
     */
    public array $panels = [];
}
