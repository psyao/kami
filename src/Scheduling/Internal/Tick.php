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
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
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

    /** Each writes its `tick-*` file: `kami:tick`, the planner's URL and visits. */
    private const array TRIGGERS = ['cli', 'web', 'visit'];

    /** Seconds after any Tick during which visits start none, and the page cache reader serves pages. */
    private const int QUIET = 300;

    /** `Application::formatCommandString('')`, which looks for the PHP binary on disk: once per process. */
    private static ?string $prefix = null;

    /**
     * Runs the overdue tasks, one after the other, starting none once the budget is spent; the rest wait for the next Tick. Then works through the queue with the budget left.
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
            // A web request declares no task: `routes/console.php` loads with the console kernel's commands, and `withSchedule()` once Artisan starts.
            // `output()` does both, as `schedule:run` has them, without building every command as `all()` would; on the command line it changes nothing.
            resolve(ConsoleKernel::class)->output();
            $lastRuns = DB::table('kami_task_runs')->pluck('last_run_at', 'task');
            $paused = Schedule::$pausable && Cache::get('illuminate:schedule:paused', false);

            foreach (resolve(Schedule::class)->events() as $event) {
                if (microtime(true) - $start >= $budget) {
                    break;
                }

                // A task whose filter throws is reported and skipped; the others still run.
                try {
                    // An `exec()` task starts a child process, which shared hosting refuses on the web: it stays overdue for a CLI Tick, and `kami:doctor` names it.
                    if ($trigger !== 'cli' && ! $event instanceof CallbackEvent && self::artisanCommand($event) === null) {
                        continue;
                    }

                    if (self::overdue($event, $lastRuns[$event->mutexName()] ?? null) && self::wouldRun($event, $paused)) {
                        $failed = ! self::runEvent($event) || $failed;
                    }
                } catch (Throwable $exception) {
                    report($exception);
                    $failed = true;
                }
            }

            self::drainQueue($budget - (microtime(true) - $start));
        } catch (Throwable $exception) {
            // No `kami_task_runs` before `kami:install`, or the database down: a failed Tick still touches its file, so visits wait five minutes
            // before the next, and the page cache reader keeps serving.
            report($exception);
            $failed = true;
        } finally {
            $lock->release();
        }

        $result = $failed ? 'FAILED' : 'OK';
        // The tasks ran: an unwritable `storage/` is never thrown, and `kami:doctor` sees no Tick. A visit reports nothing, as the page cache
        // in that `storage/` does not: it would report at every visit, each then starting a Tick, a bound broken only while the page cache is down too.
        rescue(function () use ($trigger, $start, $result): void {
            File::ensureDirectoryExists(storage_path('framework/kami'));
            File::put(storage_path("framework/kami/tick-{$trigger}"), (string) json_encode([
                'at' => Date::now()->toIso8601String(),
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'result' => $result,
            ]));
        }, report: $trigger !== 'visit');

        return $result;
    }

    /** Whether every trigger's `tick-*` file is missing or over five minutes old: one `filemtime()` each, no SQL, as the page cache reader checks them. */
    public static function stale(): bool
    {
        return max(array_map(fn (string $trigger): int => (int) @filemtime(storage_path("framework/kami/tick-{$trigger}")), self::TRIGGERS)) <= time() - self::QUIET;
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
     * Works through the host's queue as `queue:work` would, in-process and within the budget left, when its default connection is `database`:
     * shared hosting runs no worker (ADR 0020). The worker stops once the budget is spent, after the job it is running, and reports its own errors.
     * Its default queue only: a queue of another name, or another driver, needs a worker of its own.
     */
    private static function drainQueue(float $secondsLeft): void
    {
        $connection = Config::string('queue.default');

        if ($secondsLeft < 1 || Config::get("queue.connections.{$connection}.driver") !== 'database') {
            return;
        }

        // `--sleep=0`: on an empty queue, the worker would otherwise wait three seconds before stopping. `--memory=0`: its 128 MB, meant for a daemon
        // that leaks, would stop it after one job in a process that already holds more; the budget ends it, and `memory_limit` bounds it.
        // `--json`: otherwise the command asks `stty` for the terminal's width through `shell_exec()`, a child process on the web.
        // ponytail: with `pcntl` loaded, the worker leaves its SIGINT and SIGTERM handlers installed; the Tick ends right after, so they are not restored; V1 if a CLI Tick must stop on a signal after its drain.
        Artisan::call('queue:work', ['connection' => $connection, '--stop-when-empty' => true, '--max-time' => (int) $secondsLeft, '--sleep' => 0, '--memory' => 0, '--json' => true]);
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
