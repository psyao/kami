<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A relation Field's rows (ADR 0005), in pick order. `locale` is null when the Reference is shared across Locales.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kami_references', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('source_entry_id')->constrained('kami_entries')->cascadeOnDelete();
            $table->string('field', 40);
            $table->string('locale', 20)->nullable();
            $table->foreignUlid('target_entry_id')->nullable()->constrained('kami_entries')->cascadeOnDelete();
            // ponytail: no foreign key, as `kami_media` does not exist yet; M5 adds it along with the table.
            $table->ulid('target_media_id')->nullable();
            $table->unsignedInteger('position');
        });

        $this->addOneTargetCheck();

        Schema::table('kami_references', function (Blueprint $table): void {
            $table->index(['source_entry_id', 'field', 'locale', 'position'], 'kami_references_source_index');
            $table->index('target_entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kami_references');
    }

    /** Laravel's Blueprint writes no CHECK, and SQLite takes one only at creation: its empty table is created again, before it has indexes. */
    private function addOneTargetCheck(): void
    {
        $connection = Schema::getConnection();
        $table = $connection->getTablePrefix() . 'kami_references';
        $check = 'check ((target_entry_id is not null and target_media_id is null) or (target_entry_id is null and target_media_id is not null))';

        if ($connection->getDriverName() !== 'sqlite') {
            DB::statement("alter table {$table} add constraint kami_references_one_target {$check}");

            return;
        }

        $sql = DB::scalar('select sql from sqlite_master where type = ? and name = ?', ['table', $table]);
        assert(is_string($sql));
        DB::statement("drop table {$table}");
        DB::statement(substr_replace($sql, ", constraint kami_references_one_target {$check})", (int) strrpos($sql, ')'), 1));
    }
};
