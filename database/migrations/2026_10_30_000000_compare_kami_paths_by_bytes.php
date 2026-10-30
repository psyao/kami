<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MySQL's and MariaDB's default collations ignore accents, so `/café` would serve the Entry at `/cafe`. Paths are stored normalised: comparing bytes, as SQLite and PostgreSQL do, loses nothing (ADR 0008).
return new class extends Migration
{
    public function up(): void
    {
        $this->collate('utf8mb4_bin');
    }

    public function down(): void
    {
        $collation = Schema::getConnection()->getConfig('collation');

        $this->collate(is_string($collation) ? $collation : 'utf8mb4_unicode_ci');
    }

    private function collate(string $collation): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        Schema::table('kami_paths', function (Blueprint $table) use ($collation): void {
            $table->string('path', 500)->collation($collation)->change();
        });
    }
};
