<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The Media library (ADR 0017): the file and what every Locale shares, then alt text and caption per Locale (ADR 0013).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kami_media', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('site', 40);
            // Its path on `kami.media.originals_disk`.
            $table->string('file');
            // The original filename, slugged: also serves the library's search.
            $table->string('name');
            $table->string('mime', 100);
            $table->string('family', 20);
            $table->unsignedBigInteger('size');
            // Null for a document, and for an SVG that states no size.
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('checksum', 64);
            $table->boolean('decorative')->default(false);
            $table->string('credit')->nullable();
            $table->datetimes();
            // Its leading `site` also serves the library's listing.
            $table->index(['site', 'name']);
        });

        Schema::create('kami_media_translations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('media_id')->constrained('kami_media')->cascadeOnDelete();
            $table->string('locale', 20);
            $table->text('alt')->nullable();
            $table->text('caption')->nullable();
            $table->unique(['media_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kami_media_translations');
        Schema::dropIfExists('kami_media');
    }
};
