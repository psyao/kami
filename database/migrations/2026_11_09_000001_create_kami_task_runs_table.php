<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The last run of each Scheduled task, which the Tick's overdue rule reads (ADR 0020).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kami_task_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // The event's `mutexName()`, which a site may set with `createMutexNameUsing()`: a changed expression is a new task.
            $table->string('task')->unique();
            // The command, or the event's description, for `kami:doctor`.
            $table->text('description');
            // UTC. The first sighting's baseline, then each run's start.
            $table->dateTime('last_run_at');
            // `running`, `succeeded` or `failed`; null for a baseline that never ran.
            $table->string('last_status', 10)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kami_task_runs');
    }
};
