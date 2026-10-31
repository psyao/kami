<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kami_entries', function (Blueprint $table): void {
            // Not null: engines disagree on where NULL sorts. Entries sharing a position read in creation order, so a type made orderable later needs no backfill.
            $table->unsignedInteger('position')->default(0);
            $table->index(['site', 'type', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('kami_entries', function (Blueprint $table): void {
            $table->dropIndex(['site', 'type', 'position']);
            $table->dropColumn('position');
        });
    }
};
