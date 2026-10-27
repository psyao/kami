<?php

namespace Psyao\Kami\Support\Internal\Doctor;

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
}
