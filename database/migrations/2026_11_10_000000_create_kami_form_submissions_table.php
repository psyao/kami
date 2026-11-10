<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What visitors send through Forms (ADR 0015): the validated values only, never an IP or a user agent.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kami_form_submissions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('site', 40);
            $table->string('locale', 20);
            $table->string('form', 40);
            $table->foreignUlid('entry_id')->nullable()->constrained('kami_entries')->nullOnDelete();
            $table->json('data');
            $table->datetime('read_at')->nullable();
            $table->datetimes();

            // The admin's list per Form; pruning by age.
            $table->index(['site', 'form', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kami_form_submissions');
    }
};
