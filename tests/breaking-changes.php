<?php

declare(strict_types=1);

/**
 * The public-surface snapshot lines (ADR 0010) that `$after` removes or changes in a way that can break a site.
 *
 * A method only widened is not breaking: same leading parameters and return type, new trailing ones all optional,
 * on a final method or a final class's, which nothing can override. Any other change counts. Given the snapshot at the
 * last release tag, only its lines count (#603): a method added since then can change freely.
 *
 * @return list<string>
 */
function breakingSurfaceChanges(string $before, string $after, ?string $released = null): array
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

    $breaking = array_filter(array_diff($old, $new), fn (string $line): bool => ! $widened($line));

    return array_values($released === null ? $breaking : array_intersect($breaking, explode("\n", $released)));
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
function git(string $arguments): string
{
    exec("git {$arguments}", $output, $status);
    if ($status !== 0) {
        fwrite(STDERR, "git {$arguments} failed\n");
        exit(2);
    }

    return implode("\n", $output);
}

// `php tests/breaking-changes.php <base>`: CI passes the pull request's base and its title in `TITLE`, pre-push `origin/main`.
// Compares the snapshot at the merge base with `HEAD`'s, keeps the lines the last release tag has, and fails unless the
// break is marked.
if (realpath($_SERVER['argv'][0] ?? '') === __FILE__) {
    $snapshot = 'tests/.pest/snapshots/Kami/PublicSurfaceTest/it_snapshots_the_public_surface.snap';
    $base = git('merge-base ' . escapeshellarg($argv[1] ?? 'origin/main') . ' HEAD');
    $tag = git("describe --tags --abbrev=0 --match 'v*' HEAD");
    $breaking = breakingSurfaceChanges(git("show {$base}:{$snapshot}"), git("show HEAD:{$snapshot}"), git("show {$tag}:{$snapshot}"));
    $title = getenv('TITLE');

    if ($breaking !== [] && ! isMarkedBreaking($title === false ? null : $title, git("log --format=%B {$base}..HEAD"))) {
        echo "The public surface released in {$tag} lost or changed these lines: mark the title with ! or add a BREAKING CHANGE: footer, then push again\n";
        echo implode("\n", $breaking) . "\n";
        exit(1);
    }
}
