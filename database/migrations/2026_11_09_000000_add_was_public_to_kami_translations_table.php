<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Whether visitors may have seen a Translation, kept once its date moves to the future (ADR 0008, #441).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kami_translations', function (Blueprint $table): void {
            $table->boolean('was_public')->default(false);
        });

        DB::table('kami_translations')->where('published_at', '<=', now())->update(['was_public' => true]);
    }

    public function down(): void
    {
        Schema::table('kami_translations', function (Blueprint $table): void {
            $table->dropColumn('was_public');
        });
    }
};
