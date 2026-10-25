# Laravel and Filament under shared-hosting constraints (Infomaniak) vs. managed server

Research note for Kami. Scope: what Laravel, Filament and their direct dependencies allow on Infomaniak shared hosting (no persistent process, no crontab, web scheduler calling a URL every ≥ 15 min, no Redis, SSH + Composer, Apache + `.htaccess`) compared with an Infomaniak Managed Cloud Server ("Serveur Cloud managé").

- Facts only, from primary sources. Where a source is silent, this note says **not documented**.
- Retrieved 2026-10-25. Laravel docs = `13.x` branch; framework source = tag `v13.38.0`; Filament docs = `5.x` branch, source = `v5.10.3`; Livewire = `v4.4.8`; Intervention Image docs = `v4`; Glide docs = `4.0`.
- Statements marked **(source)** come from reading code, not prose documentation.

---

## 0. Current versions (confirms the brief: Laravel 13, Filament 5)

| Package | Latest stable | Released | PHP constraint | Source |
|---|---|---|---|---|
| `laravel/framework` | **v13.38.0** | 2026-10-20 | `^8.3` | https://repo.packagist.org/p2/laravel/framework.json |
| `filament/filament` | **v5.10.3** | 2026-10-20 | `^8.2` | https://repo.packagist.org/p2/filament/filament.json |
| `filament/support` (carries the Laravel constraint) | v5.10.3 | 2026-10-20 | `^8.2`, `illuminate/contracts ^11.28\|^12.0\|^13.0`, `livewire/livewire ^4.4.7`, `ext-intl` | https://repo.packagist.org/p2/filament/support.json |
| `livewire/livewire` | v4.4.8 | 2026-10-21 | `^8.1` | https://repo.packagist.org/p2/livewire/livewire.json |
| `laravel/scout` | v11.9.0 | 2026-10-06 | `^8.0`, illuminate `^9…^13` | https://repo.packagist.org/p2/laravel/scout.json |
| `intervention/image` | **4.4.0** (v4, not v3) | 2026-10-17 | `^8.3`, `ext-mbstring` | https://repo.packagist.org/p2/intervention/image.json |
| `league/glide` | 4.2.0 | 2026-10-10 | `^8.3`, `intervention/image ^4.2` | https://repo.packagist.org/p2/league/glide.json |

- Laravel 13 support policy: PHP **8.3 – 8.5**, released March 17th 2026, bug fixes until Q3 2027, security fixes until March 17th 2028. https://laravel.com/docs/13.x/releases#support-policy
- Filament 5 documented requirements: PHP 8.2+, Laravel v11.28+, Tailwind CSS v4.1+. https://filamentphp.com/docs/5.x/introduction/installation
- Effective PHP floor for Laravel 13 + Filament 5: **PHP 8.3** (Laravel's constraint is the stricter one).
- Correction to the brief's assumptions: Intervention Image is now at **v4**. Laravel 13 ships a first-party `Image` facade built on `intervention/image:^4.0` (see § 5).
- All the packages above are MIT-licensed according to their Packagist metadata.

---

## 1. Queue

### 1.1 Database driver
- Needs a `jobs` table: the default migration `0001_01_01_000002_create_jobs_table.php`, or `php artisan make:queue-table`. https://laravel.com/docs/13.x/queues#driver-prerequisites
- The Laravel 13 skeleton defaults to `QUEUE_CONNECTION=database`, with `retry_after` = `DB_QUEUE_RETRY_AFTER`, default 90 s. https://github.com/laravel/laravel/blob/13.x/config/queue.php, https://github.com/laravel/laravel/blob/13.x/.env.example
- `retry_after`: if a job has been processing for that many seconds without being released or deleted, it goes back onto the queue. The worker `--timeout` should be "at least several seconds shorter" than `retry_after`, otherwise jobs may run twice. https://laravel.com/docs/13.x/queues#job-expirations-and-timeouts

### 1.2 `queue:work` exit options
Command signature in v13.38 (source: https://github.com/laravel/framework/blob/v13.38.0/src/Illuminate/Queue/Console/WorkCommand.php):

| Option | Meaning |
|---|---|
| `--once` | Process only the next job. |
| `--stop-when-empty` | Process all jobs, then exit gracefully. |
| `--stop-when-empty-for=N` | Exit when no job has been processed for N seconds. |
| `--max-jobs=N` | Exit after N jobs. |
| `--max-time=N` | Exit after N seconds. |
| `--memory=128` | Exit above this memory (MB, or `%` of a finite `memory_limit`). |
| `--timeout=60` | Per-job timeout. |
| `--sleep=3`, `--rest=0`, `--tries=1`, `--backoff=0`, `--force` | |

Docs: https://laravel.com/docs/13.x/queues#processing-a-specified-number-of-jobs, https://laravel.com/docs/13.x/queues#processing-all-queued-jobs-then-exiting, https://laravel.com/docs/13.x/queues#processing-jobs-for-a-given-number-of-seconds, https://laravel.com/docs/13.x/queues#resource-considerations

- **(source)** `Worker::stopIfNecessary()` checks `maxTime`, `maxJobs` and `stopWhenEmpty` **between jobs**. A job that is running is not interrupted by `--max-time`. https://github.com/laravel/framework/blob/v13.38.0/src/Illuminate/Queue/Worker.php
- The PCNTL extension is required for job timeouts. `--timeout` has no effect together with `--once`. https://laravel.com/docs/13.x/queues#timeout
- **(source)** `Worker::supportsAsyncSignals()` returns `extension_loaded('pcntl')`. Without pcntl, no timeout handler is registered. https://github.com/laravel/framework/blob/v13.38.0/src/Illuminate/Queue/Worker.php

### 1.3 Running jobs from an HTTP request
- `Artisan::call()` runs any Artisan command (so also `queue:work --stop-when-empty --max-time=…`) "from a route or controller" and returns the exit code. https://laravel.com/docs/13.x/artisan#programmatically-executing-commands
  - The run inherits the web SAPI's `max_execution_time` and `memory_limit`. On the CLI, the default `max_execution_time` is 0. https://www.php.net/manual/en/info.configuration.php#ini.max-execution-time
  - Laravel docs say nothing specific about running `queue:work` through `Artisan::call` (**not documented**).
- `dispatchSync()` runs the job immediately in the current process. https://laravel.com/docs/13.x/queues#synchronous-dispatching
- **`deferred` connection**: the job runs in the current process **after the HTTP response is sent**. It is also the default failover queue. https://laravel.com/docs/13.x/queues#deferred-dispatching
  - **(source)** `DeferredQueue::push()` wraps `SyncQueue::push()` in `Illuminate\Support\defer()`. https://github.com/laravel/framework/blob/v13.38.0/src/Illuminate/Queue/DeferredQueue.php
- **`background` connection**: the job runs after the response "in a separately spawned PHP process". https://laravel.com/docs/13.x/queues#deferred-dispatching
  - **(source)** `BackgroundQueue::push()` calls `Concurrency::driver('process')->defer(...)`. It therefore needs `proc_open` (see § 1.6). https://github.com/laravel/framework/blob/v13.38.0/src/Illuminate/Queue/BackgroundQueue.php
- `sync`, `background` and `deferred` need no worker. https://laravel.com/docs/13.x/queues#queue-failover
- **`failover` driver**: on a push failure, Laravel tries the next connection in a list (skeleton example: `redis`, `database`, `sync`) and fires `QueueFailedOver`. https://laravel.com/docs/13.x/queues#queue-failover
- `dispatch()->afterResponse()` / `dispatchAfterResponse()`:
  - **Not documented** as a dispatch method in the 13.x queue docs. The docs only mention the test assertion `assertDispatchedAfterResponse`. https://laravel.com/docs/13.x/queues
  - The method still exists **(source)**. `Bus\Dispatcher::dispatchAfterResponse()` registers `dispatchSync` on `$container->terminating(...)`, or runs it synchronously when after-response dispatching is disabled. https://github.com/laravel/framework/blob/v13.38.0/src/Illuminate/Bus/Dispatcher.php, https://github.com/laravel/framework/blob/v13.38.0/src/Illuminate/Foundation/Bus/Dispatchable.php

### 1.4 Job batching
- Needs the `job_batches` table (`php artisan make:queue-batches-table`). Its name comes from `queue.batching.table`. https://laravel.com/docs/13.x/queues#job-batching
- `queue:prune-batches` should be scheduled daily, otherwise `job_batches` "can accumulate records very quickly". It has `--unfinished` and `--cancelled` options. https://laravel.com/docs/13.x/queues#pruning-batches
- The docs do not tie batching to a specific queue driver. The batch store is the database (or DynamoDB). https://laravel.com/docs/13.x/queues#storing-batches-in-dynamodb

### 1.5 `defer()` (deferred functions)
- `Illuminate\Support\defer(fn)` runs the closure after the HTTP response is sent, "without configuring or maintaining a long-running queue worker". https://laravel.com/docs/13.x/helpers#deferred-functions
- By default it only runs when the response, command or job completes successfully. There is no run on a 4xx/5xx response unless `->always()` is chained. Named deferrals can be cancelled with `defer()->forget('name')`. https://laravel.com/docs/13.x/helpers#deferred-functions
- Implementation: see § 8.

### 1.6 `Concurrency` facade
- Drivers: `process` (default), `fork`, `sync`. https://laravel.com/docs/13.x/concurrency#introduction
  - `process`: serialises closures and runs them through a hidden Artisan command in child PHP processes. `run()` accepts a `timeout:`. https://laravel.com/docs/13.x/concurrency#task-timeouts
  - `fork`: needs `spatie/fork` and works "only within PHP's CLI context, as PHP does not support forking during web requests". **(source)** It throws `RuntimeException` in web requests. https://github.com/laravel/framework/blob/v13.38.0/src/Illuminate/Concurrency/ConcurrencyManager.php
  - `sync`: runs the closures sequentially in the parent process.
- `Concurrency::defer([...])` runs the closures concurrently after the response is sent. https://laravel.com/docs/13.x/concurrency#deferring-concurrent-tasks
- **(source)** `ProcessDriver` builds `php artisan invoke-serialized-closure` with Laravel's Process factory. `defer()` runs `… 2>&1 &` inside `defer()`. https://github.com/laravel/framework/blob/v13.38.0/src/Illuminate/Concurrency/ProcessDriver.php
  - The Process factory is built on Symfony Process, which throws `LogicException` "When proc_open is not installed". https://github.com/symfony/process/blob/8.0/Process.php
  - The PHP binary comes from Symfony's `PhpExecutableFinder::find()`. It uses `PHP_BINARY` only when the SAPI is `cli`, `cli-server` or `phpdbg`. Under FPM it falls back to `PHP_PATH`, then `PHP_BINDIR/php`, then `php` on `PATH`. https://github.com/symfony/process/blob/8.0/PhpExecutableFinder.php, https://github.com/laravel/framework/blob/v13.38.0/src/Illuminate/Support/functions.php

### Shared vs. server: queue
| | Infomaniak shared | Managed Cloud Server |
|---|---|---|
| Persistent `queue:work` | No permanent process; no crontab, only the URL task scheduler (§ 9) | `systemd` via Fast Installer. Supervisor "not offered directly"; ask support. https://www.infomaniak.com/fr/support/faq/1963/installer-supervisor-sur-serveur-cloud |
| `database` queue | Available (MySQL/MariaDB) | Available |
| `redis` queue | No Redis | Redis via Fast Installer. https://www.infomaniak.com/fr/support/faq/877/installer-une-app-extension-php-sur-serveur-cloud |
| `proc_open` (needed by `background`, Concurrency `process`) | **Disabled by default**; can be enabled in the Manager (PHP / Apache tab). https://www.infomaniak.com/en/support/faq/173/manage-the-advanced-php-functions-of-a-web-hosting | Same toggle |
| pcntl (job timeouts) | **Not documented** by Infomaniak | Not in Fast Installer list (non-exhaustive); **not documented** |

---

## 2. Scheduler

- `schedule:run` evaluates every task "based on the server's current time". The documented setup is one cron entry running it **every minute**. https://laravel.com/docs/13.x/scheduling#running-the-scheduler
- **(source)** `Event::isDue()` → `expressionPasses()` checks the cron expression against `Date::now()` with `CronExpression::isDue()`. There is no catch-up of missed runs. A task is due only if the current minute matches its expression. Example: `hourly()` (`0 * * * *`) runs only if `schedule:run` is invoked during minute 0. https://github.com/laravel/framework/blob/v13.38.0/src/Illuminate/Console/Scheduling/Event.php
- `schedule:work` runs in the foreground and invokes the scheduler every minute. It is documented for local development and is a long-running process. https://laravel.com/docs/13.x/scheduling#running-the-scheduler-locally
- With sub-minute tasks, `schedule:run` keeps running until the end of the current minute. `schedule:interrupt` stops it. https://laravel.com/docs/13.x/scheduling#sub-minute-scheduled-tasks
- **From HTTP:** `Artisan::call('schedule:run')` is permitted by the generic `Artisan::call` mechanism (https://laravel.com/docs/13.x/artisan#programmatically-executing-commands). The scheduling docs do not describe it (**not documented**). **(source)**:
  - `Schedule::command()` / `exec()` tasks run with `Process::fromShellCommandline(...)`, i.e. a child process. This needs `proc_open` and the PHP binary resolution in § 1.6. https://github.com/laravel/framework/blob/v13.38.0/src/Illuminate/Console/Scheduling/Event.php, https://github.com/laravel/framework/blob/v13.38.0/src/Illuminate/Console/Scheduling/Schedule.php
  - `Schedule::call()` / `job()` (`CallbackEvent`) run in-process through `$container->call(...)`. https://github.com/laravel/framework/blob/v13.38.0/src/Illuminate/Console/Scheduling/CallbackEvent.php
- `runInBackground()` works only for `command` and `exec` tasks. https://laravel.com/docs/13.x/scheduling#background-tasks
- `withoutOverlapping(minutes = 1440)` uses the application cache to obtain locks. The default lock expiry is 24 h. `schedule:clear-cache` clears stuck locks. https://laravel.com/docs/13.x/scheduling#preventing-task-overlaps
  - **(source)** `CacheEventMutex` uses `lock()->acquire()` when the store is a `LockProvider` (not DynamoDB), otherwise `cache->add()`. File and database stores are `LockProvider`s. https://github.com/laravel/framework/blob/v13.38.0/src/Illuminate/Console/Scheduling/CacheEventMutex.php
- `onOneServer()` requires the default cache driver to be `database`, `memcached`, `dynamodb` or `redis`, with all servers on the same cache server. `file` is **not** listed. https://laravel.com/docs/13.x/scheduling#running-tasks-on-one-server
- What needs a real cron: per the docs, the scheduler is driven by a cron entry running `schedule:run` every minute. Docs mention no other trigger besides `schedule:work` (a persistent process). https://laravel.com/docs/13.x/scheduling#running-the-scheduler

### Shared vs. server: scheduler
| | Infomaniak shared | Managed Cloud Server |
|---|---|---|
| crontab | **Cloud Server only** ("disponible uniquement sur Serveur Cloud"). https://faq.infomaniak.com/350 | `crontab -e -u User`; full path to PHP, e.g. `/opt/php8.1/bin/php`. https://faq.infomaniak.com/350 |
| URL task scheduler | Custom interval minimum **15 min**. https://www.infomaniak.com/en/support/faq/2161/manage-web-hosting-task-planner | Minimum **1 min** (same FAQ) |
| HTTP timeout of the URL call | **Not documented** | **Not documented** |
| Alignment of calls to wall-clock minutes | **Not documented** | n/a with crontab |

---

## 3. Cache

Store capabilities in v13.38 **(source)**, from the class declarations in https://github.com/laravel/framework/tree/v13.38.0/src/Illuminate/Cache:

| Driver | Tags (`extends TaggableStore`) | Atomic locks (`LockProvider`) |
|---|---|---|
| `file` (`FileStore`) | **No** | Yes (`FileLock`, built on `add()` with an exclusive file lock) |
| `database` (`DatabaseStore`) | **No** | Yes (`DatabaseLock`, `cache_locks` table / `lock_table`) |
| `storage` (`StorageStore`, any filesystem disk) | **No** | **No** |
| `dynamodb` | No | Yes |
| `redis` | Yes | Yes |
| `memcached` | Yes | Yes |
| `apc` | Yes | **No** |
| `array`, `null`, `failover` | Yes | Yes |
| `session` | No | No |

- Docs, tags: "Cache tags are not supported when using the `file`, `dynamodb`, `database`, or `storage` cache drivers." https://laravel.com/docs/13.x/cache#cache-tags
- Docs, atomic locks: require `memcached`, `redis`, `dynamodb`, `database`, `file` or `array` as default driver, with "all servers … communicating with the same central cache server". https://laravel.com/docs/13.x/cache#atomic-locks
- Defaults: the Laravel 13 skeleton uses `CACHE_STORE=database`. The `file` store's `lock_path` is `storage/framework/cache/locks`. The `database` store has `lock_connection` and `lock_table`. https://laravel.com/docs/13.x/cache#configuration, https://github.com/laravel/laravel/blob/13.x/config/cache.php
- **`Cache::flexible($key, [fresh, stale], fn)`**: stale-while-revalidate. A value in the stale window is served, and a deferred function refreshes it after the response. https://laravel.com/docs/13.x/cache#swr
  - **(source)** The refresh calls `$this->store->lock(...)`, so it needs a `LockProvider` store, and is scheduled with `defer()`. Not usable on `storage` or `apc`. https://github.com/laravel/framework/blob/v13.38.0/src/Illuminate/Cache/Repository.php
- **`memo` driver** (`Cache::memo($store = null)`): keeps resolved values in memory for one request or job. Writes forget the memoized value and go to the underlying store. https://laravel.com/docs/13.x/cache#cache-memoization
- **Cache failover**: a `failover` store is documented. https://laravel.com/docs/13.x/cache#cache-failover

### Shared vs. server: cache
| | Infomaniak shared | Managed Cloud Server |
|---|---|---|
| `file`, `database` | Available | Available |
| Redis / Memcached (tags) | Not available | Redis, Memcached, APCu via Fast Installer. https://www.infomaniak.com/fr/support/faq/877/installer-une-app-extension-php-sur-serveur-cloud |

---

## 4. Page / response caching

- Laravel has **no full-page response cache** in its 13.x documentation (no such page in https://github.com/laravel/docs/tree/13.x).
- What it does provide:
  - The `cache.headers` middleware sets `Cache-Control` directives such as `public;max_age;s_maxage;stale_while_revalidate;etag`. With `etag`, it uses an xxh128 hash of the body. https://laravel.com/docs/13.x/responses#cache-control-middleware
  - Deployment caches: `optimize`, `config:cache`, `event:cache`, `route:cache`, `view:cache`. https://laravel.com/docs/13.x/deployment#optimization
  - Filament adds `filament:optimize`, which runs component caching and `icons:cache`. https://filamentphp.com/docs/5.x/deployment
- **Serving without booting the framework.** Two documented mechanisms:
  1. **Front-controller rewrite skips existing files.** The stock `public/.htaccess` rewrites to `index.php` only when `%{REQUEST_FILENAME}` is not an existing directory (`!-d`) and not an existing file (`!-f`). Any file present under `public/` is therefore served directly by Apache without PHP. https://github.com/laravel/laravel/blob/13.x/public/.htaccess; `-f`/`-d` semantics: https://httpd.apache.org/docs/2.4/mod/mod_rewrite.html#rewritecond
  2. **Early exit before autoload.** `public/index.php` checks for `storage/framework/maintenance.php` and `require`s it **before** loading Composer's autoloader and bootstrapping the app. Laravel uses this for `php artisan down --render=...`: the pre-rendered page "is rendered before any of your application's dependencies have loaded". https://github.com/laravel/laravel/blob/13.x/public/index.php, https://laravel.com/docs/13.x/configuration#pre-rendering-the-maintenance-mode-view
  - Laravel offers no generic "static page cache" feature built on either mechanism (**not documented**).
- OPcache: Filament's deployment guide recommends checking `opcache.enable`. https://filamentphp.com/docs/5.x/deployment#enabling-opcache-on-your-server
  - OPcache on Infomaniak shared hosting: **not documented** in the FAQs reviewed. On Cloud Server, `PHP_OPcache` is in the Fast Installer list. https://www.infomaniak.com/fr/support/faq/877/installer-une-app-extension-php-sur-serveur-cloud

---

## 5. Search (Scout)

- Engines: `database`, `collection`, Algolia, Meilisearch, Typesense, Turbopuffer. https://laravel.com/docs/13.x/scout#introduction
- **`database` engine**: "currently supports MySQL and PostgreSQL". It uses full-text indexes and `LIKE` clauses on the live tables, with no separate indexing step. https://laravel.com/docs/13.x/scout#database-engine
  - By default it runs `LIKE` (`%term%`) on every attribute in `toSearchableArray()`.
  - `#[SearchUsingPrefix([...])]` switches columns to `term%`. `#[SearchUsingFullText([...])]` switches columns to a full-text index, which must already exist. https://laravel.com/docs/13.x/scout#customizing-database-searching-strategies
  - **(source)** The `LIKE` operator is `ilike` on `pgsql` and `like` elsewhere. https://github.com/laravel/scout/blob/v11.9.0/src/Engines/DatabaseEngine.php
  - Semantic and hybrid search are supported only on PostgreSQL + `pgvector`. https://laravel.com/docs/13.x/scout#database-semantic-and-hybrid-search
- **`collection` engine**: loads all candidate records and filters them in PHP with `Str::is`. It is for prototypes, "a few hundred records" or tests. It works on every DB Laravel supports, **including SQLite**. https://laravel.com/docs/13.x/scout#collection-engine
- Full-text support per engine in Laravel:
  - `whereFullText` and `$table->fullText()` are supported on **MariaDB, MySQL, PostgreSQL**. MySQL/MariaDB compile to `MATCH … AGAINST`. `->language()` is PostgreSQL only.
  - **SQLite is not listed.**
  - https://laravel.com/docs/13.x/queries#full-text-where-clauses, https://laravel.com/docs/13.x/migrations#available-index-types
- Queueing: with engines other than `database` and `collection`, the docs recommend `scout.queue = true` and a queue worker. https://laravel.com/docs/13.x/scout#queueing
- DB versions Laravel 13 supports: MariaDB 10.3+, MySQL 5.7+, PostgreSQL 10.0+, SQLite 3.26.0+. https://laravel.com/docs/13.x/database#introduction

### Shared vs. server: search
| | Infomaniak shared | Managed Cloud Server |
|---|---|---|
| MySQL / MariaDB | Yes. Migrating MySQL → "une version récente de MariaDB" is **irreversible**. Exact versions **not documented**. https://www.infomaniak.com/fr/support/faq/418/use-database-management-systems-mysql-libraries-etc, https://infomaniak.com/fr/support/faq/2147/activer-mariadb-pour-remplacer-mysql | Yes |
| PostgreSQL | Only the PHP `pgsql` module, to reach a **remote** PostgreSQL. A separate DBaaS product offers PostgreSQL. https://www.infomaniak.com/fr/support/faq/418/use-database-management-systems-mysql-libraries-etc | Local PostgreSQL **not documented** in sources reviewed |
| SQLite | FAQ 418 says "SQLite version 3.x" is supported, but also lists "sqlite" as unsupported (self-contradictory) | **Not documented** |
| Meilisearch / Typesense | Not available (no persistent process) | **Not documented** in Fast Installer list reviewed |

---

## 6. Image processing

### 6.1 Laravel 13 native `Image` facade
- Laravel 13 has a first-party image API (`Illuminate\Support\Facades\Image`), "powered by Intervention Image" and supporting GD and Imagick. It needs `composer require intervention/image:^4.0`. Config: `config/images.php` / `IMAGE_DRIVER=gd|imagick`. https://laravel.com/docs/13.x/images#installation
- Features documented: `toWebp()`, `toAvif()`, `quality()`, `optimize()` (defaults to WebP q70), `storePublicly()`, custom drivers through `Image::extend()` (the docs' example is a `VipsDriver`). https://laravel.com/docs/13.x/images#encoding-images, https://laravel.com/docs/13.x/images#custom-image-drivers
- Docs warning: image manipulation "can be CPU and memory-intensive. Consider performing large image processing workloads on a queued job". https://laravel.com/docs/13.x/images#introduction

### 6.2 Intervention Image v4
- Requirements: PHP ≥ 8.3, mbstring, and one image extension. Exif is "highly recommended". Drivers: GD, Imagick, and libvips through the add-on `intervention/image-driver-vips`. https://image.intervention.io/v4/getting-started/installation
- Format matrix: https://image.intervention.io/v4/getting-started/formats

  | | GD | Imagick |
  |---|---|---|
  | JPEG, PNG, GIF, BMP | ✅ | ✅ |
  | **WebP** | ✅ | ✅ |
  | **AVIF** | ✅ | ✅ |
  | Animated WebP, TIFF, HEIC, JPEG XL, JPEG 2000, ICO | ❌ | ✅ |

  The docs add that the PHP extensions may be built without some formats.
- Runtime detection: `$driver->supports('image/avif')` (accepts an extension, MIME string, or `Format`/`MediaType`/`FileExtension` enum). https://image.intervention.io/v4/getting-started/formats
  - **(source)** The GD driver tests `imagetypes() & IMG_AVIF|IMG_WEBP|…`. https://github.com/Intervention/image/blob/4.4.0/src/Drivers/Gd/Driver.php
  - **(source)** The Imagick driver tests `count(Imagick::queryFormats($format)) >= 1`. https://github.com/Intervention/image/blob/4.4.0/src/Drivers/Imagick/Driver.php

### 6.3 PHP detection primitives
- `gd_info()` returns `"WebP Support"` and `"AVIF Support"` (the latter since PHP 8.1.0). https://www.php.net/manual/en/function.gd-info.php
- `imagetypes()` bitmask: `IMG_WEBP`, and `IMG_AVIF` (added in 8.1.0). https://www.php.net/manual/en/function.imagetypes.php
- `Imagick::queryFormats(string $pattern = "*"): array`. https://www.php.net/manual/en/imagick.queryformats.php

### 6.4 Glide (on-demand conversion over HTTP)
- Glide 4.0 manipulates images on demand through URL parameters and caches the results. Docs: "Manipulated images are automatically cached and served with far-future expires headers". It is built on Intervention Image + Flysystem, and source and cache can be different filesystems. https://glide.thephpleague.com/, https://glide.thephpleague.com/4.0/config/source-and-cache/
- Drivers: `gd` (default), `imagick`, `vips` (vips needs `intervention/image-driver-vips`). https://glide.thephpleague.com/4.0/config/image-driver/
- `fm` accepts `jpg, pjpg, png, gif, webp, avif, heic, jxl`; `heic` and `jxl` are Imagick-only. https://glide.thephpleague.com/4.0/api/encode/
- Signed URLs are "highly recommended … otherwise your application will be open to mass image-resize attacks". A `max_image_size` option exists. https://glide.thephpleague.com/4.0/config/security/, https://glide.thephpleague.com/4.0/config/setup/
- Glide's docs do not describe a first-party Laravel integration (**not documented**).

### 6.5 Memory
- No primary source gives a memory formula for decoding images (**not documented** in Laravel or Intervention docs).
- The Laravel queue docs advise freeing GD memory with `imagedestroy`. https://laravel.com/docs/13.x/queues#resource-considerations
  - Per php.net, `imagedestroy()` "has no effect" since PHP 8.0 (GD uses objects that are freed when unreferenced), and it is deprecated in 8.5. https://www.php.net/manual/en/function.imagedestroy.php

### Shared vs. server: image processing
| | Infomaniak shared | Managed Cloud Server |
|---|---|---|
| GD | Described as "often pre-installed". https://www.infomaniak.com/fr/support/faq/322/instalar-imagemagick-y-alternativas | Same |
| ImageMagick / `imagick` | ImageMagick is "disponible sur les Hébergements Web et Serveurs Cloud". Whether the PHP `imagick` extension is enabled by default on shared hosting is **not documented**; the Manager has a "PHP Extensions" tab per site. https://www.infomaniak.com/fr/support/faq/322/instalar-imagemagick-y-alternativas, https://www.infomaniak.com/en/support/faq/2116/display-technical-information-eg-phpinfo | `Imagemagick` in the Fast Installer list. https://www.infomaniak.com/fr/support/faq/877/installer-une-app-extension-php-sur-serveur-cloud |
| AVIF/WebP compiled into GD/Imagick | **Not documented**; detect at runtime (§ 6.3) | **Not documented** |
| libvips | **Not documented** | **Not documented** |

---

## 7. Filament on shared hosting

- **No Node at runtime for the stock panel.**
  - `php artisan filament:assets` copies registered CSS/JS files as-is into `public/`. https://filamentphp.com/docs/5.x/advanced/assets
  - `filament:install` adds `php artisan filament:upgrade` to Composer's `post-autoload-dump` so assets stay current on every `composer install/update`. https://filamentphp.com/docs/5.x/deployment#ensuring-assets-are-up-to-date
  - Node/Vite is needed only for a **custom theme** (Tailwind v4.1+ build) or JS needing bundling. Those are build-time steps. https://filamentphp.com/docs/5.x/introduction/installation, https://filamentphp.com/docs/5.x/advanced/assets#using-vite-compiled-javascript-files
- **Production commands**: `filament:optimize` (= `filament:cache-components` + `icons:cache`, written to `bootstrap/cache/filament`). https://filamentphp.com/docs/5.x/deployment
- **Uploads** (FileUpload → Livewire temporary uploads):
  - Livewire stores temporary files on the default disk under `livewire-tmp/`. https://livewire.laravel.com/docs/4.x/uploads#uploading-directly-to-amazon-s3
  - Defaults in `livewire.php` v4.4.8: rules `['required','file','max:12288']` (12 MB), middleware `throttle:60,1`, `max_upload_time` 5 min, `cleanup` of uploads older than 24 h = `true`. https://github.com/livewire/livewire/blob/v4.4.8/config/livewire.php
  - Without S3, "Livewire will handle file cleanup automatically". https://livewire.laravel.com/docs/4.x/uploads#configuring-automatic-file-cleanup
  - Larger files need `post_max_size`/`upload_max_filesize` in php.ini **and** the Livewire `temporary_file_upload.rules`. https://filamentphp.com/docs/5.x/forms/file-upload#file-size-validation
  - Files are copied from temp storage to the destination on submit; `moveFiles()` moves them instead. https://filamentphp.com/docs/5.x/forms/file-upload
  - **Chunked uploads**: **not documented** in either Filament 5 FileUpload docs or Livewire 4 upload docs.
- **Import / export actions**:
  - Both "use job batches and database notifications". Setup: `make:queue-batches-table`, `make:notifications-table`, `vendor:publish --tag=filament-actions-migrations`.
  - Files are processed in chunks of 100 rows by default, each chunk in "a different queued job" (`chunkSize()`).
  - Jobs use the default queue connection unless `getJobQueue()` / `getJobConnection()` is overridden. They retry for 24 h or 5 failures.
  - Export files go to the `local` disk by default (`fileDisk()`).
  - https://filamentphp.com/docs/5.x/actions/import, https://filamentphp.com/docs/5.x/actions/export
- **Database notifications**:
  - "Laravel sends database notifications using the queue. Ensure your queue is running".
  - **(source)** `Filament\Notifications\DatabaseNotification implements ShouldQueue`. https://filamentphp.com/docs/5.x/notifications/database-notifications, https://github.com/filamentphp/filament/blob/v5.10.3/packages/notifications/src/DatabaseNotification.php
  - New notifications are fetched by Livewire **polling every 30 s** (`databaseNotificationsPolling()`, may be `null`). WebSockets are optional. https://filamentphp.com/docs/5.x/notifications/database-notifications#polling-for-new-database-notifications
- **Broadcast notifications** need Laravel Echo plus a server-side WebSocket integration (e.g. Pusher). Default flash notifications go through the session. https://filamentphp.com/docs/5.x/notifications/broadcast-notifications
- Filament requires `ext-intl` through `filament/support`. https://repo.packagist.org/p2/filament/support.json
  - `intl` availability on Infomaniak shared hosting: **not documented** in FAQs reviewed.

---

## 8. PHP-FPM, `fastcgi_finish_request`, and how `defer()` runs

- **(source)** Request lifecycle: `Application::handleRequest()` runs `$kernel->handle($request)->send()`, **then** `$kernel->terminate($request, $response)`. https://github.com/laravel/framework/blob/v13.38.0/src/Illuminate/Foundation/Application.php
- **(source)** Symfony `Response::send()` calls `fastcgi_finish_request()` if the function exists. Otherwise it tries `litespeed_finish_request()`, otherwise (non-CLI) closes output buffers and calls `flush()`. https://github.com/symfony/http-foundation/blob/8.0/Response.php
- **(source)** `InvokeDeferredCallbacks::terminate()` invokes the deferred callbacks in that terminate phase. Only responses with status < 400 run them, plus callbacks marked `always`. https://github.com/laravel/framework/blob/v13.38.0/src/Illuminate/Foundation/Http/Middleware/InvokeDeferredCallbacks.php
  - So `defer()`, the `deferred` queue connection, `Cache::flexible` refreshes and `dispatchAfterResponse` all run in the same PHP worker after the response has been flushed.
- `fastcgi_finish_request()` "flushes all response data to the client and finishes the request", so time-consuming tasks can run without keeping the client connection open. https://www.php.net/manual/en/function.fastcgi-finish-request.php
- The worker stays busy until the script ends. A user note on php.net (not normative) warns that this can exhaust `pm.max_children` and that session locks persist until `session_write_close()`. https://www.php.net/manual/en/function.fastcgi-finish-request.php
- FPM `request_terminate_timeout` "is not engaged after a `fastcgi_finish_request`" unless `request_terminate_timeout_track_finished = yes` (PHP ≥ 7.3). https://www.php.net/manual/en/install.fpm.configuration.php
- `max_execution_time` default is 30 s (0 on CLI). On non-Windows it counts only script execution time, not "system calls…, stream operations, database queries". `set_time_limit()` restarts the counter. https://www.php.net/manual/en/info.configuration.php#ini.max-execution-time, https://www.php.net/manual/en/function.set-time-limit.php
  - Whether `max_execution_time` stops counting after `fastcgi_finish_request` is **not documented** on php.net.
- Infomaniak SAPI:
  - The shared-hosting Manager exposes an "FPM Extensions" field (https://www.infomaniak.com/en/support/faq/82/enabling-the-support-for-some-extensions-by-php) and a `max_children` limit (https://www.infomaniak.com/en/support/faq/2158/changing-website-limits-from-the-infomaniak-manager).
  - That a site runs under PHP-FPM, and whether `fastcgi_finish_request` is callable, is **not stated explicitly** in the FAQs reviewed.
  - **`set_time_limit` is disabled by default** on shared hosting and Cloud Server (toggle in Manager). https://www.infomaniak.com/en/support/faq/173/manage-the-advanced-php-functions-of-a-web-hosting

---

## 9. Infomaniak facts

### 9.1 Shared hosting ("Hébergement Web")
| Topic | Fact | Source |
|---|---|---|
| PHP versions | Patched versions listed: 8.5, 8.4, 8.3, 8.2, 8.1, 8.0, 7.4. CLI version is chosen via `PATH` / `/opt/phpX.Y/bin/php` and may differ from the site's version | https://www.infomaniak.com/en/support/faq/2108/change-the-php-version-for-cli |
| Site PHP version | Set in Manager. Newer versions may require updating the hosting first. Downgrading to a vulnerable version is not allowed | https://www.infomaniak.com/fr/support/faq/2053/modifier-la-version-de-php-dun-site |
| `max_execution_time` | Max **300 s** (Cloud Server 3600 s; Cloud CLI 0 = unlimited). Defaults **not documented** | https://www.infomaniak.com/en/support/faq/2158/changing-website-limits-from-the-infomaniak-manager |
| `memory_limit` | Max **1280 MB** (Cloud 2048 MB; Cloud CLI 512 MB). Defaults **not documented** | same |
| Temporary unlock | `memory_limit` 1280 MB + `max_execution_time` 60 min for 60 min, max 10×/year | same |
| `max_children` / conn. per IP | 20 / 30 | same |
| Advanced functions | `proc_open`, `popen`, `exec`, `shell_exec`, `set_time_limit`: **disabled by default**, can be enabled. `passthru`, `system`: Cloud Server only | https://www.infomaniak.com/en/support/faq/173/manage-the-advanced-php-functions-of-a-web-hosting |
| PHP extensions | Per-site "PHP Extensions" tab in Manager. No public list for shared hosting (**not documented**) | https://www.infomaniak.com/en/support/faq/2116/display-technical-information-eg-phpinfo |
| Imagick | ImageMagick available on Web hosting and Cloud Server. PHP `imagick` default state **not documented** | https://www.infomaniak.com/fr/support/faq/322/instalar-imagemagick-y-alternativas |
| Web cron | URL called at an interval of at least **15 min**. Optional password-protected URL, email notifications, execution log, manual run. **Timeout and HTTP method not documented** | https://www.infomaniak.com/en/support/faq/2161/manage-web-hosting-task-planner |
| crontab | Not available (Cloud Server only) | https://faq.infomaniak.com/350 |
| SSH | Available. Key login requires e.g. ed25519; RSA keys are refused. No agent forwarding. Key login unavailable on Starter and Node.js sites | https://www.infomaniak.com/fr/support/faq/1941/connect-via-ssh-and-use-command-line-tools (search summary of the SSH key FAQ: https://www.infomaniak.com/fr/support/faq/2054/conectar-com-chave-ssh) |
| Composer | Composer 2 pre-installed over SSH. Aliases `composer_phpX.Y`. The FAQ text says "PHP versions from 7.2 to 8.3" (it may be out of date) | https://www.infomaniak.com/en/support/faq/2118/using-composer-2-over-ssh-on-your-hosting |
| Node.js | Offered as a separate "Node.js site" type (npm/yarn supported). Availability of `node` in the SSH shell of a PHP site is **not documented** | https://www.infomaniak.com/fr/support/faq/2537/creer-un-site-nodejs-chez-infomaniak |
| MySQL/MariaDB | Unlimited MySQL/MariaDB databases. Migration to MariaDB is irreversible. Versions **not documented** | https://www.infomaniak.com/en/hosting/web-hosting, https://infomaniak.com/fr/support/faq/2147/activer-mariadb-pour-remplacer-mysql |
| PostgreSQL | Remote connection via PHP `pgsql` only (port opening). No local server | https://www.infomaniak.com/fr/support/faq/418/use-database-management-systems-mysql-libraries-etc |
| Redis / Memcached | Not on shared hosting. The product page lists Node.js, MongoDB, Redis, Varnish, Memcached as Cloud Server technologies | https://www.infomaniak.com/en/hosting/web-hosting |
| Apache / `.htaccess` | Manager advertises SSH, Cron, PHP and Apache management tools. `.htaccess` specifics **not covered** in FAQs reviewed | https://www.infomaniak.com/en/hosting/web-hosting |

### 9.2 Managed Cloud Server: what it adds
- **Real crontab** (`crontab -e -u User`), and the URL scheduler down to **1 min**. https://faq.infomaniak.com/350, https://www.infomaniak.com/en/support/faq/2161/manage-web-hosting-task-planner
- **Higher limits**: `max_execution_time` 3600 s, `memory_limit` 2048 MB. CLI: `max_execution_time` 0, `memory_limit` 512 MB. Adjustable `max_children` and `max_input_time`. https://www.infomaniak.com/en/support/faq/2158/changing-website-limits-from-the-infomaniak-manager
- **Fast Installer** apps and extensions (list described as non-exhaustive): `PHP_APCu`, `PHP_CLI`, `PHP_FPM`, `PHP_igbinary`, `PHP_OPcache`, `PHP_Swoole`, `Memcached`, `Redis`, `Imagemagick`, `NVM Node Version Manager`, … https://www.infomaniak.com/fr/support/faq/877/installer-une-app-extension-php-sur-serveur-cloud
- **Process supervision**: Supervisor is "not offered directly"; `systemd` is recommended and available in Fast Installer, or contact support for Supervisor. https://www.infomaniak.com/fr/support/faq/1963/installer-supervisor-sur-serveur-cloud
- **`passthru` / `system`** can be enabled (Cloud Server only). https://www.infomaniak.com/en/support/faq/173/manage-the-advanced-php-functions-of-a-web-hosting

---

## 10. Summary: capability matrix

| Capability | Shared (Infomaniak) | Managed Cloud Server |
|---|---|---|
| `schedule:run` every minute | No (URL ≥ 15 min; cron-expression matching is per current minute) | Yes (crontab) |
| Persistent `queue:work` | No | Yes (`systemd`) |
| Bounded `queue:work --stop-when-empty --max-time` run via HTTP | Mechanism exists (`Artisan::call`). Bounded by `max_execution_time` ≤ 300 s; `--max-time` is checked between jobs; job timeouts need pcntl (**not documented**) | Yes, also via CLI cron |
| `defer()` / `deferred` queue / `Cache::flexible` | Framework-level: yes. Depends on `fastcgi_finish_request` availability (**not documented**) for early client release | Yes |
| `background` queue, Concurrency `process`, `Schedule::command()` | Need `proc_open` (disabled by default, can be enabled) | Same toggle |
| Concurrency `fork` | No (web requests unsupported) | CLI only |
| Cache tags | No (`file`/`database`) | Yes with Redis/Memcached |
| Atomic locks | Yes (`file`, `database`) | Yes |
| Scout `database` engine | MySQL/MariaDB | MySQL/MariaDB; PostgreSQL if available |
| Image AVIF/WebP | GD/Imagick, runtime-detected | Same, plus Imagick via Fast Installer |
| Filament stock panel without Node | Yes | Yes |
| Filament import/export, DB notifications | Require queue processing + `job_batches` + `notifications` tables | Same |
| Chunked uploads | Not documented (Filament/Livewire) | Not documented |
