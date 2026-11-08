<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Psyao\Kami\Content\Actions\SaveEntry;
use Workbench\App\Content\Page;

uses(DatabaseMigrations::class);

it('marks the Translations already seen as public once, and none still to come', function () {
    $seen = resolve(SaveEntry::class)->handle(Page::class, ['title' => 'Seen', 'status' => 'published'], locale: 'fr');
    $toCome = resolve(SaveEntry::class)->handle(Page::class, ['title' => 'To come', 'status' => 'published', 'published_at' => now()->addDay()], locale: 'fr');
    $migration = ['--path' => realpath(__DIR__ . '/../../database/migrations/2026_11_09_000000_add_was_public_to_kami_translations_table.php'), '--realpath' => true, '--force' => true];

    Artisan::call('migrate:rollback', $migration);
    Artisan::call('migrate', $migration);

    expect(DB::table('kami_translations')->pluck('was_public', 'entry_id')->map(fn (mixed $value): bool => (bool) $value)->all())
        ->toEqual([$seen->id => true, $toCome->id => false]);
});
