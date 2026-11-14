<?php

declare(strict_types=1);

// From 0.9.0, the first release a site runs in production on, Kami's migrations are append-only (ADR 0035).
it('keeps every shipped migration as `database/migrations.sha256` lists it, and lists every migration', function () {
    $listed = [];
    foreach (file(dirname(__DIR__, 2) . '/database/migrations.sha256', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        [$hash, $path] = preg_split('/\s+\*?/', $line, 2);
        $listed[$path] = $hash;
    }

    $shipped = [];
    foreach (glob(dirname(__DIR__, 2) . '/database/migrations/*.php') as $file) {
        $shipped['database/migrations/' . basename($file)] = hash_file('sha256', $file);
    }

    expect($shipped)->toEqual($listed, 'A shipped migration never changes: a schema change is a new migration, whose line '
        . '`shasum -a 256 database/migrations/<file>.php >> database/migrations.sha256` appends (ADR 0035).');
});

it('keeps Eloquent models out of the migrations', function () {
    // A migration runs against the schema of its day, not the model's of today (ADR 0026).
    $mentioning = array_filter(glob(dirname(__DIR__, 2) . '/database/migrations/*.php'), fn (string $file): bool => str_contains(file_get_contents($file), 'Models\\'));

    expect(array_map(basename(...), $mentioning))->toBe([]);
});

/**
 * The table names raw SQL in `$php` writes out rather than interpolates: the table prefix reaches a name only through the
 * grammar (`wrapTable()`), `getTablePrefix()` or the schema builder, so a written-out one misses it (#605). SQLite's own
 * tables take no prefix.
 *
 * @return list<string>
 */
function unprefixedTableNames(string $php): array
{
    $names = [];
    foreach (PhpToken::tokenize($php) as $token) {
        if ($token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE])) {
            preg_match_all('/\b(?:table|into|from|references|update|join)\s+(?:if\s+(?:not\s+)?exists\s+)?\\\\?["`]?([a-z_]\w*)/i', $token->text, $match);
            $names = [...$names, ...array_diff($match[1], ['sqlite_master'])];
        }
    }

    return $names;
}

it('names tables in raw SQL through the table prefix', function () {
    $unprefixed = [];
    foreach (glob(dirname(__DIR__, 2) . '/database/migrations/*.php') as $file) {
        foreach (unprefixedTableNames(file_get_contents($file)) as $name) {
            $unprefixed[] = basename($file) . ": {$name}";
        }
    }

    expect($unprefixed)->toBe([], 'SQLite passes, MySQL and PostgreSQL under a prefix fail (#551): interpolate a name from '
        . '`$connection->getQueryGrammar()->wrapTable()`, or use the schema builder.');
});

it('catches a table name written out in raw SQL', function (string $php, array $names) {
    expect(unprefixedTableNames("<?php {$php}"))->toBe($names);
})->with([
    // #551
    'an alter table' => ['DB::statement(\'ALTER TABLE kami_entries ADD COLUMN x int\');', ['kami_entries']],
    'a quoted name' => ['DB::statement("insert into \"kami_media\" select * from {$table}");', ['kami_media']],
    'a guarded create' => ['DB::statement(\'create table if not exists kami_x (id int)\');', ['kami_x']],
    'a foreign key' => ['DB::statement("alter table {$table} add foreign key (media_id) references kami_media (id)");', ['kami_media']],
    'an interpolated name' => ['DB::statement("alter table {$table} add constraint {$prefix}kami_x check (x)");', []],
    'a schema builder call' => ['Schema::table(\'kami_entries\', fn () => null);', []],
    'SQLite\'s own table' => ['DB::scalar(\'select sql from sqlite_master where name = ?\', [$table]);', []],
]);
