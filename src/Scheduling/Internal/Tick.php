<?php

declare(strict_types=1);

namespace Psyao\Kami\Scheduling\Internal;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use DateTimeZone;
use Illuminate\Console\Application;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\StreamOutput;
use Throwable;

/**
 * Runs every overdue Scheduled task of Laravel's `Schedule` in-process, under one lock, within a budget (ADR 0020).
 *
 * @internal
 */
final class Tick
{
    /** Seconds the lock outlives the budget, for a Tick finishing its last task. */
    private const int MARGIN = 10;

    /** `Application::formatCommandString('')`, which looks for the PHP binary on disk: once per process. */
    private static ?string $prefix = null;

    /**
     * Runs the overdue tasks, one after the other, starting none once the budget is spent; the rest wait for the next Tick.
     * Touches `storage/framework/kami/tick-{trigger}` with the duration and the result, unless another Tick holds the lock.
     *
     * @return 'OK'|'BUSY'|'FAILED'
     */
    public static function run(string $trigger, int $budget): string
    {
        $lock = Cache::lock('kami:tick', $budget + self::MARGIN);

        if (! $lock->get()) {
            return 'BUSY';
        }

        $start = microtime(true);
        $failed = false;

        try {
            $lastRuns = DB::table('kami_task_runs')->pluck('last_run_at', 'task');
            $paused = Schedule::$pausable && Cache::get('illuminate:schedule:paused', false);

            foreach (resolve(Schedule::class)->events() as $event) {
                if (microtime(true) - $start >= $budget) {
                    break;
                }

                // A task whose filter throws is reported and skipped; the others still run.
                try {
                    if (self::overdue($event, $lastRuns[$event->mutexName()] ?? null) && self::wouldRun($event, $paused)) {
                        $failed = ! self::runEvent($event) || $failed;
                    }
                } catch (Throwable $exception) {
                    report($exception);
                    $failed = true;
                }
            }
        } finally {
            $lock->release();
        }

        $result = $failed ? 'FAILED' : 'OK';
        // The tasks ran: an unwritable `storage/` is reported, never thrown, and `kami:doctor` sees no Tick.
        rescue(function () use ($trigger, $start, $result): void {
            File::ensureDirectoryExists(storage_path('framework/kami'));
            File::put(storage_path("framework/kami/tick-{$trigger}"), (string) json_encode([
                'at' => Date::now()->toIso8601String(),
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'result' => $result,
            ]));
        });

        return $result;
    }

    /** Listens to Laravel's events, so a native `schedule:run` counts too. A failed write is reported, never thrown: it would stop the host's own scheduler, as before `kami:install` migrates. */
    public static function starting(ScheduledTaskStarting $event): void
    {
        self::record($event->task, ['last_run_at' => Date::now(), 'last_status' => 'running']);
    }

    public static function finished(ScheduledTaskFinished $event): void
    {
        // A non-zero exit code then throws, and `failed()` follows.
        self::update($event->task, 'succeeded');
    }

    public static function failed(ScheduledTaskFailed $event): void
    {
        self::update($event->task, 'failed');
    }

    /**
     * Due when its expression's latest due time, in its time zone, is later than its last run, so missed times collapse into one run.
     * The first sighting records a baseline and runs nothing.
     */
    private static function overdue(Event $event, mixed $lastRun): bool
    {
        if (! is_string($lastRun)) {
            self::record($event, ['last_run_at' => Date::now(), 'last_status' => null]);

            return false;
        }

        $timezone = $event->timezone instanceof DateTimeZone ? $event->timezone->getName() : ($event->timezone ?? Config::string('app.timezone'));
        $due = new CronExpression($event->expression)->getPreviousRunDate(Date::now(), allowCurrentDate: true, timeZone: $timezone);

        return $due > CarbonImmutable::parse($lastRun, 'UTC');
    }

    /** Laravel's own filters, as `schedule:run` applies them: environments, maintenance mode, a paused schedule, `when()` and `skip()`, `withoutOverlapping()`'s among them. */
    private static function wouldRun(Event $event, bool $paused): bool
    {
        return $event->runsInEnvironment(app()->environment())
            && ($event->runsInMaintenanceMode() || ! app()->isDownForMaintenance())
            && ($event->runsWhenPaused() || ! $paused)
            && $event->filtersPass(app());
    }

    /** As `schedule:run`'s `runEvent()`: a failure, a non-zero exit code included, is recorded and reported, and retried only at the next due time. */
    private static function runEvent(Event $event): bool
    {
        event(new ScheduledTaskStarting($event));
        $start = microtime(true);

        try {
            // `StringInput` reads a backslash as an escape, where the shell's single quotes keep it: a class name keeps its own.
            ($command = self::artisanCommand($event)) === null ? $event->run(app()) : self::runCommand($event, str_replace('\\', '\\\\', $command));
            event(new ScheduledTaskFinished($event, round(microtime(true) - $start, 2)));

            if ((int) $event->exitCode !== 0) {
                throw new RuntimeException("Scheduled command [{$event->command}] failed with exit code [{$event->exitCode}].");
            }

            return true;
        } catch (Throwable $exception) {
            event(new ScheduledTaskFailed($event, $exception, round(microtime(true) - $start, 2)));
            report($exception);

            return false;
        }
    }

    /**
     * `Event::run()` with `Artisan::call()` in place of the child process its protected `execute()` starts.
     * It mirrors Laravel's `Event::run()` and `start()`: a change to either breaks it, which the Lowest job and the smoke catch (ADR 0020).
     */
    private static function runCommand(Event $event, string $command): void
    {
        if ($event->shouldSkipDueToOverlapping()) {
            return;
        }

        try {
            $event->callBeforeCallbacks(app());
            $output = $event->output === $event->getDefaultOutput() ? new NullOutput : new StreamOutput(fopen($event->output, $event->shouldAppendOutput ? 'ab' : 'wb') ?: throw new RuntimeException("[{$event->output}] could not be opened."));
            $exitCode = Artisan::call($command, outputBuffer: $output);
        } catch (Throwable $exception) {
            if ($event->withoutOverlapping) {
                $event->mutex->forget($event);
            }

            throw $exception;
        }

        $event->finish(app(), $exitCode);
    }

    /**
     * A `command()` event's Artisan command line, without the PHP and `artisan` binaries `Application::formatCommandString()` put before it; null for a callback or an `exec()` event.
     *
     * ponytail: unescapes the POSIX quoting of Laravel's `ProcessUtils` only as far as backslashes go, so an argument holding a single quote reaches the command mangled; V1 if a site schedules one.
     */
    private static function artisanCommand(Event $event): ?string
    {
        $prefix = self::$prefix ??= Application::formatCommandString('');

        return $event instanceof CallbackEvent || ! str_starts_with((string) $event->command, $prefix) ? null : substr((string) $event->command, strlen($prefix));
    }

    /** @param array<string, mixed> $values */
    private static function record(Event $event, array $values): void
    {
        rescue(fn () => DB::table('kami_task_runs')->upsert(
            [['id' => (string) Str::ulid(), 'task' => $event->mutexName(), 'description' => $event->description ?? self::artisanCommand($event) ?? $event->command ?? 'Callback', 'created_at' => Date::now(), 'updated_at' => Date::now(), ...$values]],
            ['task'],
            ['description', 'updated_at', ...array_keys($values)],
        ));
    }

    private static function update(Event $event, string $status): void
    {
        rescue(fn () => DB::table('kami_task_runs')->where('task', $event->mutexName())->update(['last_status' => $status, 'updated_at' => Date::now()]));
    }
}
