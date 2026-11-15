<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A Redirect to a free address, and the dates the Redirect list shows (ADR 0008, #630). Existing rows keep null.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kami_paths', function (Blueprint $table): void {
            $table->string('target', 2048)->nullable();
            $table->nullableTimestamps();
        });
    }

    public function down(): void
    {
        Schema::table('kami_paths', function (Blueprint $table): void {
            $table->dropColumn(['target', 'created_at', 'updated_at']);
        });
    }
};
