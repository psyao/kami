<?php

declare(strict_types=1);

use Illuminate\Support\Str;

// Sites' agents read these from vendor/psyao/kami: a renamed guide, heading or class must break the build, not the agent.
dataset('boost resources', [
    'guideline' => 'resources/boost/guidelines/core.blade.php',
    'skill' => 'resources/boost/skills/kami-development/SKILL.md',
]);

/** @return list<string> */
function codeSpans(string $file): array
{
    preg_match_all('/`([^`\n]+)`/', (string) file_get_contents(dirname(__DIR__, 2) . "/{$file}"), $matches);

    return $matches[1];
}

/**
 * The anchors GitHub gives a Markdown file's headings.
 *
 * @return list<string>
 */
function headingAnchors(string $path): array
{
    preg_match_all('/^#+ (.+)$/m', (string) file_get_contents($path), $matches);

    return array_map(fn (string $heading): string => Str::of($heading)->lower()->replaceMatches('/[^\p{L}\p{N} _-]/u', '')->replace(' ', '-')->value(), $matches[1]);
}

it('names only paths and anchors the package ships', function (string $file) {
    $paths = array_filter(codeSpans($file), fn (string $span): bool => str_starts_with($span, 'vendor/psyao/kami/'));

    expect($paths)->not->toBeEmpty();

    foreach ($paths as $span) {
        [$relative, $anchor] = array_pad(explode('#', Str::after($span, 'vendor/psyao/kami/'), 2), 2, null);
        $path = dirname(__DIR__, 2) . "/{$relative}";

        expect(file_exists($path))->toBeTrue("{$span} does not exist");

        if ($anchor !== null) {
            expect(headingAnchors($path))->toContain($anchor);
        }
    }
})->with('boost resources');

it('names only classes that exist', function () {
    $spans = [...codeSpans('resources/boost/guidelines/core.blade.php'), ...codeSpans('resources/boost/skills/kami-development/SKILL.md')];
    $classes = array_filter($spans, fn (string $span): bool => str_starts_with($span, 'Psyao\\Kami\\'));

    expect($classes)->not->toBeEmpty();

    foreach ($classes as $class) {
        expect(class_exists($class))->toBeTrue("{$class} does not exist");
    }
});
