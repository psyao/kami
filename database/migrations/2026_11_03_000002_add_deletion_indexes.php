<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A hard deletion reads each table restricting or cascading from `kami_entries` for the Entry's rows. MySQL and MariaDB index every foreign key already; PostgreSQL and SQLite do not, and scanned these three.
return new class extends Migration
{
    /** @var array<string, string> */
    private array $columns = ['kami_entries' => 'parent_id', 'kami_paths' => 'entry_id', 'kami_preview_links' => 'entry_id'];

    public function up(): void
    {
        $this->each(fn (Blueprint $table, string $column) => $table->index($column));
    }

    public function down(): void
    {
        $this->each(fn (Blueprint $table, string $column) => $table->dropIndex([$column]));
    }

    private function each(Closure $change): void
    {
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach ($this->columns as $table => $column) {
            Schema::table($table, fn (Blueprint $blueprint) => $change($blueprint, $column));
        }
    }
};
