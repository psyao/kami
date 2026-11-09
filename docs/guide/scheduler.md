# Scheduler

Some of Kami's work runs on a schedule: rebuilding the sitemap, pruning expired Preview links, old page cache files, stale Conversions and old Audit log events. Kami declares these tasks on Laravel's own scheduler, and runs them, with your application's own tasks, from a **Tick**: one call that runs every task whose time has passed, in the same PHP process, with no child process (ADR 0020).

## Declaring Kami's tasks

Call `Kami::schedule()` in `routes/console.php`, as the starter kit does:

```php
use Psyao\Kami\Facades\Kami;

Kami::schedule();
```

It declares, each with `withoutOverlapping(10)`:

| Task | When |
| --- | --- |
| `kami:sitemap`, which rebuilds the [sitemap](seo.md#sitemap) when stale | hourly |
| `kami:audit:prune`, the [Audit log retention](audit-log.md#retention) | daily |
| `model:prune` for [Preview links](publishing.md#preview-links) | daily |
| `kami:page-cache:prune`, the [page cache](page-cache.md)'s files of former versions | daily |
| `kami:media:prune`, [stale Conversions](media.md#pruning-stale-files) | weekly |

Declare your own tasks beside it with Laravel's `Schedule`, as its [documentation](https://laravel.com/docs/13.x/scheduling) shows: the Tick runs them too. Give a closure task a `name()`: Kami remembers each task's last run under its mutex name, which is the same for every unnamed closure.

## The Tick

```bash
php artisan kami:tick
```

It runs every **overdue** task, one after the other, and prints `OK`, or `FAILED` with exit code 1 when a task failed. A task is overdue when its expression's latest due time has passed since its last run, so a Tick at 13:20 runs an `hourly()` task missed at 11:00, 12:00 and 13:00 once, and the next at 14:00. Laravel's `schedule:run` instead runs a task only when it is called on its exact minute.

- **First sighting.** A task the Tick has not seen before, on a fresh install or after its expression changed, is recorded and runs at its next due time.
- **Filters.** Laravel's own apply: `environments()`, maintenance mode unless `evenInMaintenanceMode()`, `schedule:pause`, `when()`, `skip()` and `withoutOverlapping()`. A task they skip stays overdue.
- **Commands** run in-process through `Artisan::call()`, so a host with `proc_open` disabled runs them. An `exec()` task runs as Laravel runs it, as a shell command.
- **Failures.** A task that throws or exits with a non-zero code is reported to your exception handler like any error, and runs again at its next due time, not at the next Tick.
- **One at a time.** A Tick takes a lock on the cache store; a second Tick while it runs prints `BUSY` and does nothing. A Tick starts no task after 50 seconds: the rest wait for the next one.

Each run is recorded in `kami_task_runs`, by the Tick or by Laravel's own `schedule:run`, so both count. Each Tick writes its time, duration and result to `storage/framework/kami/tick-cli`.

## On a server

Run the Tick every minute from cron, in place of Laravel's `schedule:run`:

```cron
* * * * * cd /path/to/site && php artisan kami:tick >> /dev/null 2>&1
```

## On shared hosting

Shared hosting such as Infomaniak's has no crontab. Run `php artisan kami:tick` over SSH, or from a scheduled task of the host's control panel that runs a command. Nothing visible waits for a Tick: Scheduled Entries go live at their time without one, and a missing sitemap is built on its first request.

## Shared hosting and a server

| Subject | Shared hosting | Server |
| --- | --- | --- |
| **Tick** | `php artisan kami:tick` over SSH or from the host's scheduled tasks | `kami:tick` from cron every minute |
| **Command tasks** | In-process through `Artisan::call()`, with no child process | The same |
| **`exec()` tasks** | Run as shell commands, where the command line allows them | The same |
| **Lock** | `Cache::lock` on the `file` or `database` store | Any store with locks |
