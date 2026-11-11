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

    expect($shipped)->toBe($listed, 'A shipped migration never changes: a schema change is a new migration, whose line '
        . '`shasum -a 256 database/migrations/<file>.php >> database/migrations.sha256` appends (ADR 0035).');
});

it('keeps Eloquent models out of the migrations', function () {
    // A migration runs against the schema of its day, not the model's of today (ADR 0026).
    $mentioning = array_filter(glob(dirname(__DIR__, 2) . '/database/migrations/*.php'), fn (string $file): bool => str_contains(file_get_contents($file), 'Models\\'));

    expect(array_map(basename(...), $mentioning))->toBe([]);
});
