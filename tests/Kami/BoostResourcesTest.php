<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

// Sites' agents read these from vendor/psyao/kami: a renamed guide, heading, class or command must break the build, not the agent.
const BOOST_RESOURCES = [
    'resources/boost/guidelines/core.blade.php',
    'resources/boost/skills/kami-development/SKILL.md',
];

/**
 * The code spans of every Boost resource that start with a prefix.
 *
 * @return list<string>
 */
function boostSpans(string $prefix): array
{
    $spans = [];

    foreach (BOOST_RESOURCES as $file) {
        preg_match_all('/`([^`\n]+)`/', (string) file_get_contents(dirname(__DIR__, 2) . "/{$file}"), $matches);
        $spans = [...$spans, ...array_filter($matches[1], fn (string $span): bool => str_starts_with($span, $prefix))];
    }

    expect($spans)->not->toBeEmpty();

    return $spans;
}

it('names only paths and anchors the package ships', function () {
    foreach (boostSpans('vendor/psyao/kami/') as $span) {
        [$file, $anchor] = array_pad(explode('#', Str::after($span, 'vendor/psyao/kami/'), 2), 2, null);

        expect(file_exists(dirname(__DIR__, 2) . "/{$file}"))->toBeTrue("{$span} does not exist");

        if ($anchor !== null) {
            expect(headingAnchors($file)->contains($anchor))->toBeTrue("{$span} names no heading");
        }
    }
});

it('names only classes, interfaces and traits that exist', function () {
    foreach (boostSpans('Psyao\\Kami\\') as $class) {
        expect(class_exists($class) || interface_exists($class) || trait_exists($class))->toBeTrue("{$class} does not exist");
    }
});

it('names only commands that exist', function () {
    foreach (boostSpans('php artisan kami:') as $command) {
        $name = Str::of($command)->after('php artisan ')->before(' ')->value();

        expect(array_key_exists($name, Artisan::all()))->toBeTrue("{$command} does not exist");
    }
});
