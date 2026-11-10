<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One row per channel a Submission's notification goes through, so a channel that failed is retried alone.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kami_form_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('submission_id')->constrained('kami_form_submissions')->cascadeOnDelete();
            $table->string('channel');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->datetime('delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->datetimes();

            $table->unique(['submission_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kami_form_deliveries');
    }
};
