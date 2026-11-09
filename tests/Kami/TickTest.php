<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Psyao\Kami\Facades\Kami;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The test's own: parallel processes share the skeleton's storage/, where the Tick writes `tick-cli`.
    $this->app->useStoragePath($this->storage = temporaryPath());
    $this->runs = [];
    $this->schedule = resolve(Schedule::class);
});

afterEach(function (): void {
    File::deleteDirectory($this->storage);
});

/** An hourly callback task that notes each run in `$this->runs`. */
function hourlyProbe(object $test, string $name = 'probe'): CallbackEvent
{
    return $test->schedule->call(function () use ($test, $name): void {
        $test->runs[] = $name;
    })->name($name)->hourly();
}

/** `kami:tick` at a given time, asserting its result. */
function tickAt(object $test, string $time, string $result = 'OK'): void
{
    $test->travelTo($time);
    $test->artisan('kami:tick')->expectsOutput($result)->assertExitCode($result === 'FAILED' ? 1 : 0);
}

it('records a baseline on a task\'s first sighting and runs nothing', function (): void {
    hourlyProbe($this);

    tickAt($this, '2026-11-09 10:30:00');

    expect($this->runs)->toBe([]);
    $this->assertDatabaseHas('kami_task_runs', ['description' => 'probe', 'last_run_at' => '2026-11-09 10:30:00', 'last_status' => null]);
});

it('runs an hourly task once after missed hours, then waits for its next due time', function (): void {
    hourlyProbe($this);
    tickAt($this, '2026-11-09 10:30:00');

    tickAt($this, '2026-11-09 13:20:00');
    tickAt($this, '2026-11-09 13:59:59');
    expect($this->runs)->toBe(['probe']);

    tickAt($this, '2026-11-09 14:00:00');
    expect($this->runs)->toBe(['probe', 'probe']);
    $this->assertDatabaseHas('kami_task_runs', ['description' => 'probe', 'last_run_at' => '2026-11-09 14:00:00', 'last_status' => 'succeeded']);
});

it('reads the expression in the event\'s time zone', function (): void {
    $this->schedule->call(function (): void {
        $this->runs[] = 'zurich';
    })->name('zurich')->dailyAt('08:00')->timezone('Europe/Zurich');
    tickAt($this, '2026-11-09 06:00:00');

    // 07:30 in UTC is 08:30 in Zurich.
    tickAt($this, '2026-11-09 07:30:00');

    expect($this->runs)->toBe(['zurich']);
});

it('keeps Laravel\'s filters, and a task they skip stays due', function (): void {
    $allowed = false;
    hourlyProbe($this, 'filtered')->when(function () use (&$allowed): bool {
        return $allowed;
    });
    hourlyProbe($this, 'production')->environments(['production']);
    hourlyProbe($this, 'maintenance');
    tickAt($this, '2026-11-09 10:30:00');
    $this->app->maintenanceMode()->activate([]);

    tickAt($this, '2026-11-09 11:10:00');
    expect($this->runs)->toBe([]);

    $allowed = true;
    $this->app->maintenanceMode()->deactivate();
    tickAt($this, '2026-11-09 11:20:00');
    expect($this->runs)->toBe(['filtered', 'maintenance']);
});

it('reports a filter that throws and runs the other tasks', function (): void {
    Exceptions::fake();
    hourlyProbe($this, 'broken')->when(function (): bool {
        throw new RuntimeException('Broken filter.');
    });
    hourlyProbe($this);
    tickAt($this, '2026-11-09 10:30:00');

    tickAt($this, '2026-11-09 11:10:00', 'FAILED');

    expect($this->runs)->toBe(['probe']);
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Broken filter.');
});

it('records a failure, reports it, and retries it only at its next due time', function (): void {
    Exceptions::fake();
    $this->schedule->call(function (): void {
        $this->runs[] = 'failing';

        throw new RuntimeException('Broken task.');
    })->name('failing')->hourly();
    hourlyProbe($this);
    tickAt($this, '2026-11-09 10:30:00');

    tickAt($this, '2026-11-09 11:10:00', 'FAILED');
    tickAt($this, '2026-11-09 11:20:00');
    expect($this->runs)->toBe(['failing', 'probe']);
    $this->assertDatabaseHas('kami_task_runs', ['description' => 'failing', 'last_status' => 'failed']);
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Broken task.');

    tickAt($this, '2026-11-09 12:00:00', 'FAILED');
    expect($this->runs)->toBe(['failing', 'probe', 'failing', 'probe']);
});

it('runs a command task in-process, its arguments intact, and fails on a non-zero exit code', function (): void {
    Exceptions::fake();
    $test = $this;
    Artisan::command('tick:probe {--model=} {--fail}', function () use ($test): int {
        $test->runs[] = $this->option('model');

        return $this->option('fail') ? 1 : 0;
    });
    $this->schedule->command('tick:probe', ['--model' => 'Psyao\Kami\Content\Internal\PreviewLink'])->hourly();
    $this->schedule->command('tick:probe --fail')->hourly();
    tickAt($this, '2026-11-09 10:30:00');

    tickAt($this, '2026-11-09 11:10:00', 'FAILED');

    expect($this->runs)->toBe(['Psyao\Kami\Content\Internal\PreviewLink', null]);
    $this->assertDatabaseHas('kami_task_runs', ['description' => "tick:probe --model='Psyao\\Kami\\Content\\Internal\\PreviewLink'", 'last_status' => 'succeeded']);
    $this->assertDatabaseHas('kami_task_runs', ['description' => 'tick:probe --fail', 'last_status' => 'failed']);
    Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), 'failed with exit code [1]'));
});

// A server's crontab replacing `schedule:run` with `kami:tick` keeps the host's `exec()` tasks.
it('runs an exec() task from the command line', function (): void {
    $this->schedule->exec('touch', [$file = "{$this->storage}/touched"])->hourly();
    tickAt($this, '2026-11-09 10:30:00');

    tickAt($this, '2026-11-09 11:10:00');

    expect($file)->toBeFile();
})->skip(! function_exists('proc_open'), 'proc_open is disabled (tests/infomaniak.ini).');

it('leaves a task running elsewhere due', function (): void {
    $event = hourlyProbe($this)->withoutOverlapping(10);
    tickAt($this, '2026-11-09 10:30:00');
    $this->travelTo('2026-11-09 11:10:00');
    $event->mutex->create($event);

    tickAt($this, '2026-11-09 11:10:00');
    expect($this->runs)->toBe([]);

    $event->mutex->forget($event);
    tickAt($this, '2026-11-09 11:15:00');
    expect($this->runs)->toBe(['probe']);
});

it('answers BUSY and runs nothing while another Tick holds the lock', function (): void {
    hourlyProbe($this);
    tickAt($this, '2026-11-09 10:30:00');
    $this->travelTo('2026-11-09 11:10:00');
    $lock = Cache::lock('kami:tick', 60);
    $lock->get();

    tickAt($this, '2026-11-09 11:10:00', 'BUSY');

    expect($this->runs)->toBe([])
        ->and(json_decode(File::get("{$this->storage}/framework/kami/tick-cli"), true))->toMatchArray(['at' => '2026-11-09T10:30:00+00:00', 'result' => 'OK']);
    $lock->release();
});

it('touches tick-cli with the duration and the result', function (): void {
    tickAt($this, '2026-11-09 10:30:00');

    expect(json_decode(File::get("{$this->storage}/framework/kami/tick-cli"), true))
        ->toHaveKeys(['at', 'duration_ms', 'result'])
        ->toMatchArray(['at' => '2026-11-09T10:30:00+00:00', 'result' => 'OK']);
});

it('records the runs of a native schedule:run', function (): void {
    hourlyProbe($this);
    $this->travelTo('2026-11-09 11:00:00');

    $this->artisan('schedule:run')->assertSuccessful();

    expect($this->runs)->toBe(['probe']);
    $this->assertDatabaseHas('kami_task_runs', ['description' => 'probe', 'last_run_at' => '2026-11-09 11:00:00', 'last_status' => 'succeeded']);
});

it('runs every task Kami::schedule() declares from a Tick', function (): void {
    $this->app->usePublicPath($this->storage);
    Storage::fake('local');
    Storage::fake('public');
    Kami::schedule();
    tickAt($this, '2026-11-09 10:30:00');

    tickAt($this, '2026-11-16 10:30:00');

    expect(DB::table('kami_task_runs')->orderBy('description')->pluck('last_status', 'description')->all())->toBe([
        'kami:audit:prune' => 'succeeded',
        'kami:media:prune' => 'succeeded',
        'kami:page-cache:prune' => 'succeeded',
        'kami:sitemap' => 'succeeded',
        "model:prune --model='Psyao\\Kami\\Content\\Internal\\PreviewLink'" => 'succeeded',
    ]);
});

// Between `composer update` and `kami:install`, the table is missing: the host's own scheduler keeps working.
it('leaves a native schedule:run running when the runs cannot be recorded', function (): void {
    Exceptions::fake();
    Schema::drop('kami_task_runs');
    hourlyProbe($this);
    $this->travelTo('2026-11-09 11:00:00');

    $this->artisan('schedule:run')->assertSuccessful();

    expect($this->runs)->toBe(['probe']);
    Exceptions::assertReported(QueryException::class);
});
