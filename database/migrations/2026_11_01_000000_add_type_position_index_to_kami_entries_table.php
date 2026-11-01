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
            // `ofType()->ordered()`, with or without a Site: walked in order, so a listing never sorts the whole type.
            $table->index(['type', 'position', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('kami_entries', function (Blueprint $table): void {
            $table->dropIndex(['type', 'position', 'id']);
        });
    }
};
