<?php

declare(strict_types=1);

namespace Psyao\Kami\Tests;

use LogicException;

/** The PHP examples a guide marks with `<!-- kami:example [setup] -->`, run against the Workbench Site (docs/development.md § Guide examples). */
final class GuideExamples
{
    private static int $runs = 0;

    /**
     * The examples a Markdown file marks, by the guide's name and the line of their opening fence.
     *
     * @return array<string, array{string, ?string, string}> Name, setup and code of each example.
     */
    public static function in(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        $examples = [];

        foreach ($lines as $i => $line) {
            if (! str_starts_with(trim($line), '<!-- kami:')) {
                continue;
            }

            $name = basename($path) . ':' . ($i + 2);

            if (preg_match('/^<!-- kami:example(?: ([a-z-]+))? -->$/', trim($line), $marker) !== 1 || trim($lines[$i + 1] ?? '') !== '```php') {
                throw new LogicException("{$name}: a marker reads `<!-- kami:example [setup] -->`, on the line right before a ```php fence.");
            }

            // A fence indented under a list item: its code carries the same indent.
            $indent = strlen($line) - strlen(ltrim($line));
            $code = [];

            for ($j = $i + 2; trim($lines[$j] ?? '```') !== '```'; $j++) {
                $code[] = substr($lines[$j], $indent);
            }

            $examples[$name] = [$name, $marker[1] ?? null, implode("\n", $code)];
        }

        return $examples;
    }

    /**
     * Evaluates an example's code, named `guide.md:line`, with these variables in scope, in a namespace of its own that replaces the example's: two examples
     * may declare the same class. A guide's `App\` class reads as the Workbench's, or as `tests/Fixtures/Guide`'s where the Workbench lacks one. Returns the namespace.
     *
     * @param  array<string, mixed>  $variables
     */
    public static function evaluate(string $name, string $code, array $variables = []): string
    {
        // Named after the example too: a fatal error, such as an abstract method left out, names the class it stopped at.
        $namespace = 'Psyao\\Kami\\Tests\\GuideExamples\\Example' . ++self::$runs . '_' . preg_replace('/\W/', '_', $name);

        (static function (array $__variables, string $__code): void {
            extract($__variables);
            eval($__code);
        })($variables, "namespace {$namespace};\n" . preg_replace_callback(
            '/(?<![\\\\\w])App\\\\([\\w\\\\]+)/',
            static fn (array $name): string => array_find(["Workbench\\App\\{$name[1]}", "Psyao\\Kami\\Tests\\Fixtures\\Guide\\{$name[1]}"], class_exists(...)) ?? $name[0],
            (string) preg_replace('/^namespace [^;]+;$/m', '', $code),
        ));

        return $namespace;
    }
}
