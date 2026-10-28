<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Handles are short so that `(site, locale, path)` fits MySQL's 3,072-byte index limit in utf8mb4. Dates are `datetime`, in UTC.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kami_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('site', 40);
            $table->string('type', 40);
            $table->datetimes();
        });

        Schema::create('kami_translations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('entry_id')->constrained('kami_entries')->cascadeOnDelete();
            $table->string('locale', 20);
            $table->string('title');
            $table->string('slug');
            $table->string('status', 20);
            $table->dateTime('published_at')->nullable();
            $table->datetimes();
            $table->unique(['entry_id', 'locale']);
            $table->index(['status', 'published_at']);
        });

        Schema::create('kami_paths', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('site', 40);
            $table->string('locale', 20);
            $table->string('path', 500);
            $table->foreignUlid('entry_id')->nullable()->constrained('kami_entries')->restrictOnDelete();
            $table->string('target', 2000)->nullable();
            $table->unsignedSmallInteger('status');
            $table->unique(['site', 'locale', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kami_paths');
        Schema::dropIfExists('kami_translations');
        Schema::dropIfExists('kami_entries');
    }
};
