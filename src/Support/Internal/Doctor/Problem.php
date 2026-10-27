<?php

namespace Psyao\Kami\Support\Internal\Doctor;

use Illuminate\Support\Str;

/**
 * One thing `kami:doctor` found wrong, with how to fix it and where the guide explains it.
 *
 * @internal
 */
final readonly class Problem
{
    /** @param string $guide A file of `docs/guide/` and its anchor, e.g. `installation.md#requirements`. */
    public function __construct(
        public Severity $severity,
        public string $message,
        public string $fix,
        public string $guide,
    ) {}

    /** The guide shipped with the installed version, relative to the site when Kami sits in its `vendor/`. */
    public function guidePath(): string
    {
        return Str::after(dirname(__DIR__, 4) . "/docs/guide/{$this->guide}", base_path() . DIRECTORY_SEPARATOR);
    }
}
