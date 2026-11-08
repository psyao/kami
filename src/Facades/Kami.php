<?php

declare(strict_types=1);

namespace Psyao\Kami\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Kami's entry point for sites.
 *
 * @method static void routes()
 * @method static mixed remember(string $key, \DateTimeInterface|\DateInterval|int $ttl, \Closure $callback)
 * @method static void withoutPageCache()
 * @method static void flushCache()
 *
 * @see \Psyao\Kami\Kami
 */
final class Kami extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Psyao\Kami\Kami::class;
    }
}
