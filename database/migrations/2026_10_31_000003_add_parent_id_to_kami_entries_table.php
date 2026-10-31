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
            $table->foreignUlid('parent_id')->nullable()->constrained('kami_entries')->restrictOnDelete();
            // Siblings: their order, and the next Position.
            $table->index(['site', 'type', 'parent_id', 'position']);
            $table->dropIndex(['site', 'type', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('kami_entries', function (Blueprint $table): void {
            $table->index(['site', 'type', 'position']);
            $table->dropIndex(['site', 'type', 'parent_id', 'position']);
            $table->dropForeign(['parent_id']);
            $table->dropColumn('parent_id');
        });
    }
};
