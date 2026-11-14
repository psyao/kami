<?php

declare(strict_types=1);

namespace Psyao\Kami\Routing\Internal;

use RuntimeException;

/**
 * A Path longer than `kami_paths.path` holds, its ancestors' slugs included.
 *
 * @internal
 */
final class PathTooLong extends RuntimeException
{
    public function __construct(public readonly string $path)
    {
        parent::__construct('The Path [' . mb_substr($path, 0, 60) . '…] is longer than ' . Path::MAX_LENGTH . ' characters.');
    }
}
