<?php

namespace Psyao\Kami\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Kami's entry point for sites.
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
