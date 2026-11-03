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
            // The Trash, never emptied automatically; a datetime as `created_at` and `updated_at` are.
            $table->softDeletesDatetime();
        });
    }

    public function down(): void
    {
        Schema::table('kami_entries', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
