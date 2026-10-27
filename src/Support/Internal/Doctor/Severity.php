<?php

namespace Psyao\Kami\Support\Internal\Doctor;

/**
 * How bad a Problem is: an error fails `kami:doctor`, a warning only fails it with `--strict`.
 *
 * @internal
 */
enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
}
