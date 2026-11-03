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
