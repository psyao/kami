<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Psyao\Kami\Admin\InitialsAvatarProvider;

/** Renders the avatar of a User with this name, and returns the SVG it carries. */
function avatarSvgFor(string $name): SimpleXMLElement
{
    $uri = (new InitialsAvatarProvider)->get((new User)->forceFill(['name' => $name]));

    expect($uri)->toStartWith('data:image/svg+xml;base64,');

    $svg = simplexml_load_string(base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')), strict: true));

    expect($svg)->toBeInstanceOf(SimpleXMLElement::class);

    return $svg;
}

it('draws the initials of the User name', function (string $name, string $initials) {
    expect(trim((string) avatarSvgFor($name)->text))->toBe($initials);
})->with([
    'one word' => ['Ada', 'A'],
    'two words' => ['ada lovelace', 'AL'],
    'several words: first and last' => ['Augusta Ada King Lovelace', 'AL'],
    'accents' => ['émile Zola', 'ÉZ'],
    'decomposed accent' => ["E\u{301}mile", "E\u{301}"],
    'leading punctuation' => ['[SYSTEM] admin', 'SA'],
    'digits' => ['R2 D2', 'RD'],
    'extra spaces' => ['  Grace   Hopper ', 'GH'],
]);

it('gives a valid image without letters', function (string $name) {
    expect(trim((string) avatarSvgFor($name)->text))->toBe('');
})->with(['empty' => [''], 'punctuation only' => ['<&> "\'']]);
