<?php

declare(strict_types=1);

namespace Psyao\Kami\Menus\Internal;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The Menus `config/kami.php` declares under `kami.menus`, read when used, never at boot (ADR 0025).
 *
 * @internal
 */
final class Menus
{
    /**
     * How deep a declared Menu's items may nest, 1 to 3.
     *
     * @throws InvalidArgumentException For a handle not declared, or a depth out of range.
     */
    public static function depth(string $handle): int
    {
        $menus = Config::array('kami.menus', []);

        if (! array_key_exists($handle, $menus)) {
            throw new InvalidArgumentException("No Menu [{$handle}] is declared under kami.menus.");
        }

        // The width of `kami_menu_items.menu`.
        if (strlen($handle) > 40) {
            throw new InvalidArgumentException("The Menu handle [{$handle}] is over 40 characters.");
        }

        $depth = is_array($menus[$handle]) ? ($menus[$handle]['depth'] ?? 1) : null;

        if (! is_int($depth) || $depth < 1 || $depth > 3) {
            throw new InvalidArgumentException("The Menu [{$handle}] declares a depth of 1 to 3 under kami.menus.{$handle}.depth.");
        }

        return $depth;
    }

    /** `kami-menus.{handle}.label`, or the humanised handle: the admin's and the `<nav>`'s name. */
    public static function label(string $handle): string
    {
        $key = "kami-menus.{$handle}.label";

        return Lang::has($key) ? (string) __($key) : Str::headline($handle);
    }
}
