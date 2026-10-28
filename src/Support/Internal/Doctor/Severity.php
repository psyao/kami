<?php

declare(strict_types=1);

namespace Psyao\Kami\Support\Internal\Doctor;

/**
 * How bad a Problem is: an error fails `kami:doctor`, a warning only fails it with `--strict`, an information never does.
 *
 * @internal
 */
enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';
}
