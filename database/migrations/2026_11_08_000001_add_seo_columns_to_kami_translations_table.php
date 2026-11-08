<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What a Translation says to search engines, per Locale with no fallback, and the Site's default description (#23 decisions 1 and 3).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kami_translations', function (Blueprint $table): void {
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->boolean('noindex')->default(false);
        });
        // The home page's description when it has none of its own.
        Schema::table('kami_type_site_settings', function (Blueprint $table): void {
            $table->text('default_description')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('kami_translations', function (Blueprint $table): void {
            $table->dropColumn(['meta_title', 'meta_description', 'noindex']);
        });
        Schema::table('kami_type_site_settings', function (Blueprint $table): void {
            $table->dropColumn('default_description');
        });
    }
};
