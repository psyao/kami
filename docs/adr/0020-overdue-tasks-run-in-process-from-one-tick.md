# Scheduled tasks run when overdue, in-process, from one Tick that cron, a secret URL or visits can start

Infomaniak shared hosting has no crontab, only a task planner that calls a URL every 15 minutes at best, and `proc_open` is off on the web SAPI (map Notes). Laravel's `schedule:run` fits neither fact. It runs a task only when the current minute matches its expression and never catches up, so an `hourly()` task runs only if a call lands on minute 0. And it starts `Schedule::command()` tasks as child processes. So Kami keeps Laravel's `Schedule` as the only place tasks are declared, and replaces only two things: when a task is due, and how a command task is started.

`kami:tick` walks `$schedule->events()`. It keeps Laravel's own filters (`filtersPass()`, environments, maintenance mode). It replaces `isDue()` with an **overdue** rule: a task is due when `CronExpression::getPreviousRunDate(now)` is later than its last run. Missed due times collapse into one run. Each due event runs through Laravel's `Event::run()`, so `withoutOverlapping()`, hooks and `onSuccess()` behave as usual. On the web, an Artisan `command()` event runs in-process through `Artisan::call()`, after the prefix that `Application::formatCommandString()` added is stripped. An `exec()` event never runs on the web, and `kami:doctor` reports it. After the tasks, the Tick works through the `database` queue with whatever budget is left (`queue:work --stop-when-empty --max-time=<rest>`).

Three Tick triggers, always armed, call the same Tick under one non-blocking lock (`Cache::lock('kami:tick')`, expiring after the budget plus a margin):

| Trigger | Where | Budget |
| --- | --- | --- |
| CLI | `* * * * * php artisan kami:tick` in a server crontab | 50 s |
| Web | `GET\|POST /kami/tick/{token}`, called by the host's planner | `min(max_execution_time − 15 s, 40 s)` |
| Visit | `defer()` after a front or admin response, when the last Tick is older than 5 min | 10 s |

There is no mode setting. The visit trigger goes quiet on its own once cron or the URL ticks often enough, and a site with no configuration at all still gets Ticks from its visits.

The decision trades simplicity (principle 6) for stability (principle 5): Kami re-implements the due check and the command launch instead of calling `schedule:run`. In return, Kami's tasks and the host's own tasks (aguet's `contact:notify` among them) run on shared hosting with the same declaration they use on a server.

## Considered options

- **`Artisan::call('schedule:run')` from the URL.** No catch-up, and `command()` tasks need `proc_open`. aguet's `/cron/{token}` works around this by calling one command directly, so its `Schedule` entry is inert in production (retrospective §2.5).
- **A Kami task registry** (a `KamiTask` interface with simple intervals). Simpler, but tasks would be declared in two places, and the host's `Schedule` entries would stay inert on shared hosting.
- **A `KAMI_SCHEDULER=cron|web|visits` setting.** An option screen for something the Tick can work out by itself.
- **Answering the web call at once, then working after `fastcgi_finish_request()`.** The planner's undocumented timeout would stop mattering, but its failure emails would never fire. Kami works inside the request and answers `200 OK`, `200 BUSY` or `500 FAILED`.

## Consequences

- Last runs live in `kami_task_runs`, keyed by the event's `mutexName()`. The table is filled by listening to Laravel's `ScheduledTaskStarting`, `ScheduledTaskFinished` and `ScheduledTaskFailed`, so a native `schedule:run` also counts. The first sighting of a task (a fresh install, or a changed expression) sets a baseline and does not run the task. A failed run is recorded as a run (`last_status = failed`), reported to Laravel's exception handler, and retried only at the next due time.
- Each trigger touches `storage/framework/kami/tick-{cli,web,visit}`. The visit check is a single `filemtime()`, with no SQL, and the file contents (duration, result) feed `kami:doctor`.
- Kami's own side effects never depend on the queue. They are written to the database first, then run through `defer()`, with a Scheduled task as a backstop sweep. The `deferred` queue connection is not used for anything that must survive. The `database` queue is kept for large chunked work (Filament import and export, imports) and the host's jobs. A Kami task stays short, and long work dispatches chunked jobs. Kami never relies on `set_time_limit()`.
- The web route is registered by `Kami::routes()`, outside the `web` middleware group (no session, cookies or CSRF). It answers 404 while `KAMI_TICK_TOKEN` is empty or does not match (`hash_equals`). `kami:tick:url` generates a token and prints the `.env` line and the URL, and writes nothing to host files (ADR 0001).
- Kami's own tasks are declared by an explicit `Kami::schedule()` call in `routes/console.php`, which the starter kit includes.
- Visit Ticks run after responses that pass through Kami's public middleware or `KamiPlugin`'s, with status below 400. The tick route, `HEAD` requests and the Conversion route (ADR 0017) are excluded. Pages served as static files trigger nothing.
- The server recipe replaces `schedule:run` with `kami:tick` in the crontab. A `queue:work` worker under systemd is optional.
- `kami:doctor` reports:
  - the last Tick per trigger, with a warning after 24 h without one;
  - overdue and failed tasks;
  - `exec()` events;
  - queue size, the age of the oldest job, and failed jobs;
  - the last Tick's duration against its budget;
  - a missing `Kami::schedule()` or `KAMI_TICK_TOKEN`.

  An admin health widget is a V1 door over the same data.
