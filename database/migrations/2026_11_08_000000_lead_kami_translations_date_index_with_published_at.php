<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Led by `status`, SQLite with no statistics read it for every Entry's visibility check rather than the `(entry_id, locale)`
// unique (#222). Led by `published_at`, it still serves `Visibility::nextChange()`, whose range holds only Scheduled rows.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kami_translations', function (Blueprint $table): void {
            $table->dropIndex(['status', 'published_at']);
            $table->index(['published_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('kami_translations', function (Blueprint $table): void {
            $table->dropIndex(['published_at', 'status']);
            $table->index(['status', 'published_at']);
        });
    }
};
