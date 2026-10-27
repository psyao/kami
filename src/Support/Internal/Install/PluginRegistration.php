<?php

namespace Psyao\Kami\Support\Internal\Install;

/**
 * Whether a host panel carries `KamiPlugin`, as the plugin itself reports it: the core never asks Filament (ADR 0011).
 *
 * @internal
 */
final class PluginRegistration
{
    public bool $onAPanel = false;
}
