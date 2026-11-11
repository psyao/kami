<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The Legacy ids of imported Entries and Media items, read by `Import` alone (ADR 0026).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kami_import_keys', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('site', 40);
            // A system and its id space, such as `wordpress.post`.
            $table->string('source', 100);
            $table->string('legacy_id');
            $table->foreignUlid('entry_id')->nullable()->constrained('kami_entries')->cascadeOnDelete();
            $table->foreignUlid('media_id')->nullable()->constrained('kami_media')->cascadeOnDelete();
            // A SHA-256 of what the importer handed over.
            $table->string('checksum', 64);
            $table->datetime('seen_at');
            $table->datetimes();

            $table->unique(['site', 'source', 'legacy_id']);
        });

        // Exactly one of the two is set. SQLite adds no check to an existing table: there `Import` alone ensures it.
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE kami_import_keys ADD CONSTRAINT kami_import_keys_one_target CHECK ((entry_id IS NULL) <> (media_id IS NULL))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kami_import_keys');
    }
};
