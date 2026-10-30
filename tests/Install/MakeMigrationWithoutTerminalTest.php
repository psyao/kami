<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

// Here rather than beside the other kami:make:migration tests: the Kami suite also runs where proc_open is disabled (tests/infomaniak.ini).
it('refuses to guess a rename from a process with no terminal, even without --no-interaction', function () {
    // A real process outside the testing environment, where Laravel treats every command as having a terminal.
    $database = tempnam(sys_get_temp_dir(), 'kami-make-migration-');
    $artisan = Process::path(dirname(__DIR__, 2))->env(['APP_ENV' => 'local', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database]);
    $artisan->run(['php', 'vendor/bin/testbench', 'migrate', '--force'])->throw();
    // The process's SQLite file, not the test's own connection.
    (new PDO("sqlite:{$database}"))->exec('ALTER TABLE kami_type_label RENAME COLUMN colour TO legacy');

    $result = $artisan->run(['php', 'vendor/bin/testbench', 'kami:make:migration', 'label']);
    unlink($database);

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toContain('kami_type_label loses legacy and gains colour', 'renaming it to one of colour', 'dropping it');
});
