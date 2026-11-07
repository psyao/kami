<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The Audit log (ADR 0022). No foreign key: an event outlives its User and its subject.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kami_audit_events', function (Blueprint $table): void {
            /** @var class-string<Model> $users */
            $users = config('auth.providers.users.model');

            $table->ulid('id')->primary();
            // UTC, as every Kami date.
            $table->dateTime('occurred_at');
            // Typed like the host's User key.
            $table->foreignIdFor(new $users, 'user_id')->nullable();
            $table->string('actor', 10);
            $table->string('event', 100);
            $table->string('subject_type', 100)->nullable();
            // A ULID or a host's integer or UUID key.
            $table->string('subject_id', 40)->nullable();
            $table->string('site', 40)->nullable();
            // Truncated to /24 or /48, null outside HTTP.
            $table->string('ip', 45)->nullable();
            $table->json('context');

            $table->index('occurred_at');
            $table->index(['user_id', 'occurred_at']);
            $table->index(['event', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kami_audit_events');
    }
};
