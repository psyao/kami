<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Draws a User's initials as an inline SVG: unlike Filament's default, no name leaves the site (ADR 0023).
 * The host sets it on its panel with `->defaultAvatarProvider(InitialsAvatarProvider::class)`.
 */
final class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model | Authenticatable $record): string
    {
        // First letter or digit of the first and last words, with its combining accents. Scrubbed, since a latin1 column would fail `/u` on every admin page.
        preg_match_all('/(?<!\S)[^\p{L}\p{N}\s]*([\p{L}\p{N}]\p{M}*)/u', mb_scrub(Filament::getNameForDefaultAvatar($record), 'UTF-8'), $matches);
        $letters = $matches[1];
        $initials = count($letters) > 1 ? $letters[0] . end($letters) : implode('', $letters);

        $background = Color::convertToHex((string) (FilamentColor::getColor('gray')[950] ?? Color::Gray[950]));
        $text = htmlspecialchars(mb_convert_case($initials, MB_CASE_UPPER_SIMPLE, 'UTF-8'), ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            . '<rect width="64" height="64" fill="' . $background . '"/>'
            . '<text x="32" y="32" dominant-baseline="central" text-anchor="middle" fill="#ffffff" font-family="system-ui, sans-serif" font-size="26">' . $text . '</text>'
            . '</svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
