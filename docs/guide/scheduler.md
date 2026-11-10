# Scheduler

Some of Kami's work runs on a schedule: retrying Submissions' mail, rebuilding the sitemap, pruning expired Preview links, old page cache files, stale Conversions and old Audit log events. Kami declares these tasks on Laravel's own scheduler, and runs them, with your application's own tasks, from a **Tick**: one call that runs every task whose time has passed, in the same PHP process, with no child process (ADR 0020).

## Declaring Kami's tasks

Call `Kami::schedule()` in `routes/console.php`, as the starter kit does:

```php
use Psyao\Kami\Facades\Kami;

Kami::schedule();
```

It declares, each with `withoutOverlapping(10)`:

| Task | When |
| --- | --- |
| `kami:forms:deliver`, which retries the [delivery](forms.md#retries) of Submissions | every 15 minutes |
| `kami:sitemap`, which rebuilds the [sitemap](seo.md#sitemap) when stale | hourly |
| `kami:forms:prune`, the [retention of Submissions](forms.md#retention) | daily |
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
- **Commands** run in-process through `Artisan::call()`, so a host with `proc_open` disabled runs them. An `exec()` task runs as Laravel runs it, as a shell command, from `kami:tick` only: the URL and visits below leave it overdue.
- **Failures.** A task that throws or exits with a non-zero code is reported to your exception handler like any error, and runs again at its next due time, not at the next Tick.
- **One at a time.** A Tick takes a lock on the cache store; a second Tick while it runs prints `BUSY` and does nothing. A Tick starts no task after 50 seconds: the rest wait for the next one.

Each run is recorded in `kami_task_runs`, by the Tick or by Laravel's own `schedule:run`, so both count. Each Tick writes its time, duration, result and budget to `storage/framework/kami/tick-cli`, `tick-web` or `tick-visit`, after what started it. A Tick started by the URL or a visit also writes what the web's PHP looks like, for `kami:doctor` ([Deployment](deployment.md#the-web-php)).

Three things start a Tick, all always on: there is nothing to choose.

| Trigger | Started by | Stops starting tasks after |
| --- | --- | --- |
| `kami:tick` | A server's cron, or you over SSH | 50 seconds |
| The URL | The host's planner calling `/kami/tick/{token}` | 40 seconds, or `max_execution_time` less 15 |
| A visit | A front or admin page, once no Tick ran for five minutes | 10 seconds |

A site with cron or a planner keeps a Tick recent, so visits stop starting any. One with neither still runs its tasks from its visits.

## The queue

Kami itself dispatches no job: what it must do later is written to the database and done after the response or by a task. Your application's own queued jobs need a worker, and shared hosting runs none, so each Tick works through the queue after its tasks, with the time they left, as `queue:work --stop-when-empty` would, in the same process. Set the `database` connection in `.env`, as the starter kit does:

```dotenv
QUEUE_CONNECTION=database
```

- **What it takes.** Jobs on the default connection's own queue (`default` unless `DB_QUEUE` names another), and only when that connection uses the `database` driver. Jobs on another queue, or on Redis or SQS, wait for a worker of your own.
- **Time.** The Tick starts no job once its time is spent, as for tasks: up to 10 seconds after a visit. A job already running finishes, so keep each one short: on the web, PHP's `max_execution_time` stops a job mid-way, and Laravel takes it up again after the connection's `retry_after` (90 seconds) as a new attempt, which fails it once its `$tries` are used.
- **Failures** go where a worker sends them, as Laravel's [queue documentation](https://laravel.com/docs/13.x/queues#dealing-with-failed-jobs) describes.

Keep tasks and jobs short. Work that takes minutes, such as importing thousands of rows, dispatches one job per chunk, each resuming where the last stopped, so that every Tick moves it forward and a Tick that stops mid-way loses nothing.

A server can run Laravel's own worker beside the Tick, under systemd or Supervisor, as Laravel's [queue documentation](https://laravel.com/docs/13.x/queues#supervisor-configuration) shows: jobs then run as soon as they are dispatched, and the Tick finds the queue empty.

## Checking the scheduler

`kami:doctor` reads the `tick-*` files, `kami_task_runs` and the queue's tables, and warns about:

- **no Tick** from any trigger for 24 hours, or none yet, and a **most recent Tick that failed**;
- an **empty `KAMI_TICK_TOKEN`** while `kami:tick` has not run for 24 hours: only visits start a Tick then;
- a **Tick over its budget**, the last one of each trigger in the past 24 hours: a task or a job ran on past the time it had;
- **`Kami::schedule()` not called** in `routes/console.php`;
- **`exec()` tasks** while `kami:tick`, which alone runs them, has not run for 24 hours;
- **late tasks**, whose first missed due time is over an hour old, leaving out those their own filters skip, so a task due every minute under a planner calling every 15 stays quiet;
- **failed tasks**, whose last run failed or started over an hour ago and never finished, as when PHP's `max_execution_time` stops it. A `runInBackground()` task counts as finished once it starts: Laravel tells nothing of its end;
- **failed jobs**, and a job waiting on the default `database` connection for over an hour, leaving out one being worked on.

It also prints each trigger's last Tick, with its age, result and duration, and the number of jobs on the default `database` queue.

On a developer's machine and in CI (`APP_ENV` `local` or `testing`), neither a web request nor a Tick runs: the first two lines, and the web's missing snapshot, are informations there, so `kami:doctor --strict` still passes. The admin dashboard's **Needs attention** widget shows them after the next `kami:doctor` run ([Installation](installation.md#checking-the-environment)).

## On a server

Run the Tick every minute from cron, in place of Laravel's `schedule:run`:

```cron
* * * * * cd /path/to/site && php artisan kami:tick >> /dev/null 2>&1
```

## On shared hosting

Shared hosting such as Infomaniak's has no crontab, and its task planner calls a URL rather than a command. Generate the URL's secret token:

```bash
php artisan kami:tick:url
```

It prints a `KAMI_TICK_TOKEN=` line for `.env`, which it never writes itself, and the URL on your Site URL: have the planner call it as often as it allows ([Hosting](hosting.md#the-task-planner)). Running the command again generates another token: the old URL stops working once `.env` holds the new one. While `KAMI_TICK_TOKEN` is empty or the token differs, the URL answers 404.

The URL runs the Tick inside the request and answers `OK`, `BUSY` while another Tick runs, or `FAILED` with status 500, in plain text, so the planner's own failure report fires. It takes no session, cookie or CSRF token, and `GET` or `POST`.

Between the planner's calls, and on a site with no planner, visits start the Tick: after a page is sent, the visitor's PHP worker runs it, so nobody waits for it. Nothing visible waits for a Tick either: Scheduled Entries go live at their time without one, and a missing sitemap is built on its first request.

## Shared hosting and a server

| Subject | Shared hosting | Server |
| --- | --- | --- |
| **Tick** | The planner calls `/kami/tick/{token}` every 15 minutes at best; visits start one after five minutes without | `kami:tick` from cron every minute; the URL and visits stay armed and go quiet |
| **Command tasks** | In-process through `Artisan::call()`, with no child process | The same |
| **`exec()` tasks** | Never run from the URL or a visit: only from `kami:tick` over SSH, where the command line allows them | Run from cron's `kami:tick` |
| **Queue** | `database`, worked through by each Tick with the time its tasks left, no worker | The same, and optionally Laravel's `queue:work` under systemd |
| **Lock** | `Cache::lock` on the `file` or `database` store | Any store with locks |
