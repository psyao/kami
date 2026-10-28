<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Psyao\Kami\Content\ContentType;

/**
 * The Content Types listed in `config/kami.php`, found by class or handle.
 *
 * @internal
 */
final class ContentTypes
{
    // ponytail: read on every call, so a test may change `kami.types`; boot validation (#100) builds it once.
    public function find(string $classOrHandle): ?ContentType
    {
        foreach (Config::array('kami.types') as $class) {
            $type = is_string($class) ? app()->make($class) : null;

            if (! $type instanceof ContentType) {
                throw new InvalidArgumentException('Every entry of kami.types is a class extending ' . ContentType::class . '.');
            }

            if ($class === $classOrHandle || $type->handle() === $classOrHandle) {
                return $type;
            }
        }

        return null;
    }
}
