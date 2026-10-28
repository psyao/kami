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
    public function find(string $classOrHandle): ?ContentType
    {
        return collect($this->all())->first(fn (ContentType $type, string $class): bool => $class === $classOrHandle || $type->handle() === $classOrHandle);
    }

    /**
     * Keyed by class.
     *
     * @return array<string, ContentType>
     */
    // ponytail: read on every call, so a test may change `kami.types`; boot validation (#100) builds it once.
    public function all(): array
    {
        $types = [];

        foreach (Config::array('kami.types') as $class) {
            $type = is_string($class) ? app()->make($class) : null;

            if (! $type instanceof ContentType) {
                throw new InvalidArgumentException('Every entry of kami.types is a class extending ' . ContentType::class . '.');
            }

            $types[$class] = $type;
        }

        return $types;
    }
}
