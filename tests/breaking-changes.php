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

/**
 * The released lines (#603) that `$head` breaks and `$base` did not: a line added since the last tag changes freely, and
 * a break an earlier pull request made is not this one's. Both sides are read against the release, so a released method
 * widened since the tag and then removed still counts.
 *
 * @return list<string>
 */
function newlyBrokenLines(string $released, string $base, string $head): array
{
    return array_values(array_diff(breakingSurfaceChanges($released, $head), breakingSurfaceChanges($released, $base)));
}

/**
 * Whether the break is marked where release-please reads it after the squash merge: `!` in the pull request's title, or
 * a `BREAKING CHANGE:` footer in a commit. Without a title (pre-push), a commit subject with `!` stands in for it.
 */
function isMarkedBreaking(?string $title, string $log): bool
{
    $bang = '/^[a-z]+(\([^)]*\))?!:/m';

    return preg_match('/^BREAKING[ -]CHANGE:/m', $log) === 1
        || ($title === null ? preg_match($bang, $log) === 1 : preg_match($bang, $title) === 1);
}

/** Runs a git command, its output trimmed; any failure stops the script, as a missing tag must not pass the gate. */
function gitOrExit(string $arguments): string
{
    exec("git {$arguments}", $output, $status);
    if ($status !== 0) {
        fwrite(STDERR, "git {$arguments} failed\n");
        exit(2);
    }

    return implode("\n", $output);
}

// `php tests/breaking-changes.php <base> [<title>]`: CI passes the pull request's base and title, pre-push `origin/main` alone.
if (realpath($_SERVER['argv'][0] ?? '') === __FILE__) {
    $snapshot = 'tests/.pest/snapshots/Kami/PublicSurfaceTest/it_snapshots_the_public_surface.snap';
    $base = gitOrExit('merge-base ' . escapeshellarg($argv[1] ?? 'origin/main') . ' HEAD');
    $tag = gitOrExit("describe --tags --abbrev=0 --match 'v*' HEAD");
    $title = $argv[2] ?? null;
    $breaking = newlyBrokenLines(gitOrExit("show {$tag}:{$snapshot}"), gitOrExit("show {$base}:{$snapshot}"), gitOrExit("show HEAD:{$snapshot}"));

    if ($breaking !== [] && ! isMarkedBreaking($title, gitOrExit("log --format=%B {$base}..HEAD"))) {
        echo getenv('GITHUB_ACTIONS') === 'true' ? '::error::' : '';
        echo "The public surface released in {$tag} lost or changed these lines: ";
        echo $title === null ? "mark a commit's subject with ! or add a BREAKING CHANGE: footer" : 'mark the title with ! or add a BREAKING CHANGE: footer, then push again';
        echo "\n" . implode("\n", $breaking) . "\n";
        exit(1);
    }
}
