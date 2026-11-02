<?php

declare(strict_types=1);

/**
 * The public-surface snapshot lines (ADR 0010) that `$after` removes or changes in a way that can break a site.
 *
 * A method only widened is not breaking: same leading parameters and return type, new trailing ones all optional,
 * on a final method or a final class's, which nothing can override. Any other change counts.
 *
 * @return list<string>
 */
function breakingSurfaceChanges(string $before, string $after): array
{
    $old = array_filter(explode("\n", $before));
    $new = array_filter(explode("\n", $after));
    $added = array_diff($new, $old);

    $widened = function (string $line) use ($old, $added): bool {
        // `class Owner::method() <modifiers> function method(<parameters>): <return type>`. A parenthesised return type does not match: it counts.
        if (! preg_match('/^class (\S+)::\w+\(\) (.*?) function (.*)(\)(: [^()]+)?)$/', $line, $match)) {
            return false;
        }

        [, $owner, $modifiers, , $tail] = $match;
        $ownerLine = (string) current(array_filter($old, fn (string $candidate): bool => str_starts_with($candidate, "class {$owner} ")));
        if (! in_array('final', [...explode(' ', $modifiers), ...explode(' ', $ownerLine)], true)) {
            return false;
        }

        $head = substr($line, 0, -strlen($tail));
        $separator = str_ends_with($head, '(') ? '' : ', ';

        foreach ($added as $candidate) {
            if (! str_starts_with($candidate, $head . $separator) || ! str_ends_with($candidate, $tail)) {
                continue;
            }

            $extra = substr($candidate, strlen($head . $separator), -strlen($tail));

            // Every new parameter has a default or is variadic.
            if ($extra !== '' && ! preg_match('/(?<!\.\.\.)\$\w++(?! = )/', $extra)) {
                return true;
            }
        }

        return false;
    };

    return array_values(array_filter(array_diff($old, $new), fn (string $line): bool => ! $widened($line)));
}

// CI: `php tests/breaking-changes.php <before.snap> <after.snap>` prints the breaking lines.
if (realpath($_SERVER['argv'][0] ?? '') === __FILE__) {
    echo implode("\n", breakingSurfaceChanges((string) file_get_contents($argv[1]), (string) file_get_contents($argv[2])));
}
