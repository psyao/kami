<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Psyao\Kami\Content\Actions\SaveEntry;
use Workbench\App\Content\Page;

uses(DatabaseMigrations::class);

// The test migrates again in a batch of its own, which the trait's closing rollback alone would undo, leaving a half-built schema
// on a database that outlives the test (MariaDB, MySQL, PostgreSQL) for the next test that reads it without migrating.
afterEach(fn () => Artisan::call('migrate:fresh', ['--force' => true]));

it('adds parent_id to a site with content, keeping what hangs off its Entries', function () {
    $entry = resolve(SaveEntry::class)->handle(Page::class, ['title' => 'One', 'status' => 'published'], locale: 'fr');

    // With the later index on `parent_id`, which SQLite cannot keep once the column goes.
    $migration = ['--path' => array_map(realpath(...), [__DIR__ . '/../../database/migrations/2026_10_31_000003_add_parent_id_to_kami_entries_table.php', __DIR__ . '/../../database/migrations/2026_11_03_000002_add_deletion_indexes.php']), '--realpath' => true, '--force' => true];

    Artisan::call('migrate:rollback', $migration);
    expect(Schema::hasColumn('kami_entries', 'parent_id'))->toBeFalse();

    Artisan::call('migrate', $migration);

    expect(Schema::hasColumn('kami_entries', 'parent_id'))->toBeTrue()
        ->and(DB::table('kami_entries')->where('id', $entry->id)->exists())->toBeTrue()
        ->and(DB::table('kami_translations')->where('entry_id', $entry->id)->count())->toBe(1)
        ->and(DB::table('kami_paths')->where('entry_id', $entry->id)->count())->toBeGreaterThan(0);
});
