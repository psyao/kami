<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/breaking-changes.php';

$final = "class Psyao\\Kami\\Content\\Actions\\SaveEntry final class\n";
$abstract = "class Psyao\\Kami\\Content\\Actions\\SaveEntry abstract class\n";
$method = 'class Psyao\Kami\Content\Actions\SaveEntry::handle() public function handle(';
$finalMethod = str_replace('public function', 'final public function', $method);

it('reads a widened method as not breaking', function (string $owner, string $before, string $after): void {
    expect(breakingSurfaceChanges($owner . $before, $owner . $after))->toBe([]);
})->with([
    // #269
    'an optional argument' => [$final, "{$method}string \$type, ?string \$locale = NULL): Psyao\\Kami\\Content\\Entry", "{$method}string \$type, ?string \$locale = NULL, ?DateTimeInterface \$updatedAt = NULL): Psyao\\Kami\\Content\\Entry"],
    'a first argument' => [$final, "{$method}): void", "{$method}array \$values = []): void"],
    'a variadic argument' => [$final, "{$method}string \$type): void", "{$method}string \$type, string ...\$names): void"],
    'on a final method' => [$abstract, "{$finalMethod}string \$type): void", "{$finalMethod}string \$type, int \$limit = 10): void"],
]);

it('reads any other change as breaking', function (string $owner, string $before, string $after): void {
    expect(breakingSurfaceChanges($owner . $before, $owner . $after))->toBe([$before]);
})->with([
    'a required argument' => [$final, "{$method}string \$type): void", "{$method}string \$type, int \$limit): void"],
    'a required argument after an optional one' => [$final, "{$method}string \$type): void", "{$method}string \$type, int \$limit = 10, int \$offset): void"],
    'an overridable method' => [$abstract, "{$method}string \$type): void", "{$method}string \$type, int \$limit = 10): void"],
    'a changed leading argument' => [$final, "{$method}string \$type): void", "{$method}?string \$type, int \$limit = 10): void"],
    'a changed return type' => [$final, "{$method}string \$type): void", "{$method}string \$type, int \$limit = 10): int"],
    'a removed line' => [$final, "{$method}string \$type): void", ''],
]);

// #603: only the released surface needs `!`, so a line the last tag lacks changes freely, and a break an earlier pull
// request made is not this one's.
it('reads as breaking only what the pull request breaks in the released surface', function (string $released, string $base, string $head, array $breaking) use ($final): void {
    expect(newlyBrokenLines($final . $released, $final . $base, $final . $head))->toBe($breaking);
})->with(function () use ($method): array {
    $string = "{$method}string \$type): void";
    $widened = "{$method}string \$type, int \$limit = 10): void";
    $int = "{$method}int \$type): void";

    return [
        'a change to a line added since the last tag' => ['', $string, $int, []],
        'a change to a released line' => [$string, $string, $int, [$string]],
        'a released line widened since the last tag, then removed' => [$string, $widened, '', [$string]],
        'a released line widened again' => [$string, $widened, "{$method}string \$type, int \$limit = 10, int \$offset = 0): void", []],
        'a break an earlier pull request made' => [$string, $int, $int, []],
    ];
});

it('reads a break as marked by the title, a footer, or a subject when there is no title', function (?string $title, string $log, bool $marked): void {
    expect(isMarkedBreaking($title, $log))->toBe($marked);
})->with([
    'a title with !' => ['feat!: drop Entry::foo()', "feat: drop Entry::foo()\n", true],
    'a scoped title with !' => ['fix(admin)!: drop Entry::foo()', '', true],
    'a footer' => ['feat: drop Entry::foo()', "feat: drop\n\nBREAKING CHANGE: Entry::foo() is gone\n", true],
    'a subject with ! and no title' => [null, "feat!: drop Entry::foo()\n", true],
    'a subject with ! under a plain title' => ['feat: drop Entry::foo()', "feat!: drop Entry::foo()\n", false],
    'nothing' => ['feat: drop Entry::foo()', "feat: drop Entry::foo()\n", false],
]);
