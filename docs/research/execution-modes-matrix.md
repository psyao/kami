# Execution modes matrix: Infomaniak shared hosting vs. managed Cloud Server

Research note for Kami, resolving issue #2. For each subsystem: what Laravel 13 and Filament 5 allow on Infomaniak shared hosting ("Hébergement Web") and on a managed Cloud Server ("Serveur Cloud managé"), given what was measured on aguet.dev's host. Then the three facts left open in `docs/velox-retrospective.md` §5.2, each with what the documentation says and a measurement procedure.

- Facts only, no recommendations. **Not documented** means no primary source states it.
- Retrieved 2026-10-25. Versions as in `docs/research/mutualise.md:6` (Laravel 13.38.0, Filament 5.10.3, Livewire 4.4.8); PHP source = branch `PHP-8.5`; Symfony = `8.0`.
- `mut` = `docs/research/mutualise.md`, `retro` = `docs/velox-retrospective.md`. Facts already sourced there are cited by line, not re-researched.
- **(source)** = read from code, not prose documentation. **(measured)** = measured by Steve on aguet.dev, PHP 8.5.12 (`retro:261-280`); another site of the same account may differ.

---

## 1. Baseline: the two environments

| Fact | Shared hosting | Managed Cloud Server |
|---|---|---|
| Persistent process (worker, daemon) | None (`mut:96`) | `systemd` via Fast Installer; Supervisor on request (`mut:329`) |
| crontab | No; Cloud Server only (`mut:122`, `mut:316`) | Yes, `crontab -e -u User`, full PHP path (`mut:122`) |
| URL task planner | Custom interval ≥ 15 min (`mut:123`) | ≥ 1 min (`mut:123`) |
| Web SAPI | **(measured)** `FPM/FastCGI` behind Apache (`retro:265`) | `PHP_FPM` in Fast Installer list (`mut:328`) |
| Web `disable_functions` | **(measured)** `exec, mail, passthru, pcntl_exec, popen, proc_open, shell_exec, system` (`retro:266`). Documented default: `proc_open`, `popen`, `exec`, `shell_exec`, `set_time_limit` off, can be enabled; `passthru`/`system` Cloud only (`mut:312`) | Same toggles, plus `passthru`/`system` (`mut:330`) |
| CLI `disable_functions` | **(measured)** none (`retro:266`) | **Not documented** |
| Web `max_execution_time` | **(measured)** 60 s (`retro:267`); max 300 s, 60 min unlock 10×/year (`mut:308-310`) | Max 3600 s (`mut:327`) |
| CLI `max_execution_time` | **(measured)** 0 (`retro:267`) | 0 (`mut:327`) |
| Web `memory_limit` | **(measured)** 640M (`retro:268`); max 1280 MB (`mut:309`) | Max 2048 MB; CLI 512 MB (`mut:327`) |
| `max_children` | 20 (`mut:311`) | Adjustable (`mut:327`) |
| `pcntl` | **(measured)** loaded (`retro:271`); `pcntl_exec` disabled on web (`retro:266`) | **Not documented** (`mut:100`) |
| Redis / Memcached / APCu | No (`mut:322`) | Fast Installer (`mut:328`) |
| OPcache | **(measured)** loaded, `opcache.enable` Off, no Manager control (`retro:270`, `retro:279`) | `PHP_OPcache` in Fast Installer (`mut:328`) |
| Database | **(measured)** remote MariaDB 10.11.19 (`retro:274`); no local PostgreSQL (`mut:321`) | MySQL/MariaDB; local PostgreSQL **not documented** (`mut:197`) |
| Node on the server | **(measured)** absent from SSH `PATH` (`retro:278`) | `NVM` in Fast Installer (`mut:328`) |

---

## 2. Matrix per subsystem

Each cell lists the mechanisms Laravel 13 / Filament 5 provide that run in that environment, and what blocks the others.

| Subsystem | Infomaniak shared hosting | Infomaniak managed Cloud Server |
|---|---|---|
| **Queue** | **Works:** `database` driver (`mut:34`); `sync`, `deferred` (runs in-process after the response via `defer()`, `mut:63-64`); `dispatchAfterResponse()` (exists in source, undocumented as dispatch method, `mut:69-71`); bounded `queue:work --stop-when-empty --max-time=N` run by `Artisan::call()` from an HTTP request, inheriting web `max_execution_time` (`mut:59-61`), `--max-time` checked between jobs only (`mut:54`); same command over SSH/CLI with no time limit. **Blocked on web:** `background` driver and Concurrency `process` need `proc_open` (`mut:65-66`, `mut:89-90`), disabled on web (`retro:266`); Concurrency `fork` throws in web requests (`mut:86`); persistent `queue:work` (no process supervisor); Redis queue. **Job `--timeout`:** needs pcntl (`mut:55-56`); pcntl is loaded on CLI and web (`retro:271`), and has no effect with `--once` (`mut:55`). **Filament:** import/export use job batches (`job_batches` table) and queued chunk jobs; database notifications are `ShouldQueue` (`mut:267-275`). | **Works:** everything on the left, plus persistent `queue:work` under `systemd` (`mut:96`), `redis` queue (`mut:98`), `proc_open` can be enabled for `background`/Concurrency `process` (`mut:99`). |
| **Scheduler** | **Works:** URL task planner every ≥ 15 min (`mut:123`) hitting an app route that calls `Artisan::call('schedule:run')` (generic mechanism, undocumented for the scheduler, `mut:110`). **Constraints:** a task is due only if the current minute matches its cron expression, no catch-up (`mut:107`), so an `hourly()` task runs only if the call lands on minute 0; planner clock alignment **not documented** (§5). `Schedule::command()`/`exec()` spawn a child process through `proc_open` (`mut:111`), disabled on web (`retro:266`); `Schedule::call()`/`job()` run in-process (`mut:112`). `withoutOverlapping()` works with `file`/`database` locks (`mut:114-115`); `onOneServer()` needs `database` (not `file`) cache (`mut:116`). Budget per call = web `max_execution_time`, 60 s measured (`retro:267`). Planner HTTP method and timeout **not documented** (§5). | **Works:** crontab running `/opt/phpX.Y/bin/php artisan schedule:run` every minute, the documented setup (`mut:106`, `mut:122`); CLI `max_execution_time` 0 (`mut:327`); `Schedule::command()` runs (CLI has `proc_open`; **(measured)** on shared CLI, Cloud **not documented**); URL planner down to 1 min (`mut:123`). |
| **Cache** | **Works:** `file`, `database` stores; both support atomic locks, neither supports tags (`mut:133-136`, `mut:145-146`); `Cache::flexible` (stale-while-revalidate) needs a lock store and refreshes through `defer()` (`mut:148-149`); `memo` driver (`mut:150`); `failover` store (`mut:151`). Skeleton default `CACHE_STORE=database` (`mut:147`); measured database is remote (`retro:274`). **Blocked:** tags (no Redis/Memcached), `apc` (`mut:157`). | **Works:** everything on the left, plus Redis, Memcached (tags + locks) and APCu (tags, no locks) via Fast Installer (`mut:141`, `mut:157`). |
| **Page cache** | Laravel 13 has no full-page response cache (`mut:163`). **Works:** `cache.headers` middleware with ETag (`mut:165`); static files under `public/` served by Apache without PHP because the stock `.htaccess` rewrites only non-existent paths (`mut:169`); early-exit hook in `public/index.php` before autoload, as used by `artisan down --render` (`mut:170`); deployment caches `optimize`, `filament:optimize` (`mut:166-167`). Apache gzips HTML and assets, no Brotli (`retro:280`). **Missing:** OPcache is off (`retro:270`), so every PHP-served request recompiles the framework; enabling it is open (§3). | **Works:** same mechanisms; OPcache installable (`mut:328`). Varnish is listed as a Cloud Server technology on the product page (`mut:322`); its setup is **not documented** in the sources reviewed. |
| **Search** | **Works:** Scout `database` engine on MySQL/MariaDB (`LIKE`, prefix, or `MATCH … AGAINST` full-text) (`mut:180-182`, `mut:187`); `collection` engine on any database including SQLite (`mut:185`). Measured MariaDB 10.11.19 (`retro:274`) is above Laravel's 10.3 floor (`mut:191`). **Blocked:** Meilisearch/Typesense (no persistent process) (`mut:199`); PostgreSQL only remote (`mut:197`), so `pgvector` semantic search is not local (`mut:184`). SQLite has no `whereFullText` (`mut:188`). | **Works:** same. Meilisearch/Typesense: **not documented** in the Fast Installer list reviewed (`mut:199`); a `systemd` service is possible in principle (`mut:329`), installation path **not documented**. Local PostgreSQL **not documented** (`mut:197`). |
| **Image processing** | **Works:** Laravel 13 `Image` facade on Intervention Image v4 (`mut:206`); Glide 4 for on-demand conversions over HTTP (`mut:232`). **(measured)** GD with WebP, **no AVIF** (`retro:272`); `imagick` 3.8.1 over ImageMagick 6.9.11-60 listing AVIF, HEIC, WebP (`retro:273`); `exif` loaded (`retro:271`). Runtime detection per driver: `imagetypes()`, `Imagick::queryFormats()`, Intervention `supports()` (`mut:222-229`). Bound by web `memory_limit` 640M and `max_execution_time` 60 s (`retro:267-268`); no memory formula documented (`mut:239`). libvips **not documented** (`mut:249`). | **Works:** same libraries; ImageMagick via Fast Installer (`mut:247`); higher limits (`mut:327`); work can move to a persistent queue worker. libvips **not documented** (`mut:249`). |
| **Mail** | **Works:** `smtp` transport. Infomaniak SMTP: `mail.infomaniak.com`, port 465 SSL/TLS or 587 STARTTLS, authentication required with the full address, TLS ≥ 1.2, sender must equal the authenticated address ([FAQ 576](https://www.infomaniak.com/en/support/faq/576/using-phpmailer-on-infomaniak)). Outgoing limit: rolling 24 h, each recipient counted separately; for Web Hosting "limit depends on the email service used" (kSuite / Mail Service: 200 or 500 per 24 h by plan), otherwise 50 via unauthenticated `mail()` ([FAQ 2065](https://www.infomaniak.com/en/support/faq/2065/understanding-the-24-hour-outgoing-email-limit)). `log`, `array`, `failover`, `roundrobin` transports (Laravel [mail docs](https://laravel.com/docs/13.x/mail#failover-configuration), [config/mail.php](https://github.com/laravel/laravel/blob/13.x/config/mail.php)). `Mail::queue()` / `ShouldQueue` mailables need a configured queue ([docs](https://laravel.com/docs/13.x/mail#queueing-mail)), i.e. any of the queue mechanisms above. **Blocked on web:** `sendmail` transport, because Symfony's `SendmailTransport` uses `ProcessStream`, which calls `proc_open` **(source)** ([SendmailTransport.php](https://github.com/symfony/mailer/blob/8.0/Transport/SendmailTransport.php), [ProcessStream.php:47](https://github.com/symfony/mailer/blob/8.0/Transport/Smtp/Stream/ProcessStream.php)); PHP `mail()` disabled by default and off on aguet (`retro:266`), "strongly" discouraged by Infomaniak ([FAQ 2150](https://www.infomaniak.com/en/support/faq/2150/use-unauthenticated-not-recommended-email-sending-from-a-website)). SMTP socket timeout defaults to `default_socket_timeout` **(source)** ([SocketStream.php:46](https://github.com/symfony/mailer/blob/8.0/Transport/Smtp/Stream/SocketStream.php)). Laravel skeleton default `MAIL_MAILER=log` ([config/mail.php](https://github.com/laravel/laravel/blob/13.x/config/mail.php)). | **Works:** same SMTP; `sendmail` if `proc_open` is enabled and a sendmail binary exists (binary **not documented**). Same outgoing limits apply per address ([FAQ 2065](https://www.infomaniak.com/en/support/faq/2065/understanding-the-24-hour-outgoing-email-limit)). |
| **After-response work (`defer()`)** | **Works:** under FPM, Symfony `Response::send()` calls `fastcgi_finish_request()`, then Laravel runs deferred callbacks in `terminate()` (`mut:285-288`); measured SAPI is FPM (`retro:265`). Only for responses < 400 unless `->always()` (`mut:80`, `mut:287`). Same mechanism backs the `deferred` queue, `Cache::flexible` refresh and `dispatchAfterResponse` (`mut:288`). The FPM worker stays busy until the callbacks end; 20 `max_children` (`mut:290`, `mut:311`). FPM `request_terminate_timeout` is not engaged after `fastcgi_finish_request` unless `request_terminate_timeout_track_finished = yes` (`mut:291`). Whether `max_execution_time` still applies: §4. **Blocked:** `Concurrency::defer()` with the `process` driver (needs `proc_open`, `mut:88-90`). | **Works:** same, with `max_execution_time` up to 3600 s (`mut:327`); `Concurrency::defer()` if `proc_open` is enabled (`mut:99`). |

---

## 3. Open fact: can OPcache be enabled for a site on shared hosting?

### What the documentation says

- php.net: `opcache.enable` is `INI_ALL`, default `1`, but "can not be enabled at runtime through `ini_set`, it can only be disabled. Trying to enable it in a script will generate a warning." ([php.net OPcache ini](https://www.php.net/manual/en/opcache.configuration.php#ini.opcache.enable), source [doc-en ini.xml](https://github.com/php/doc-en/blob/master/reference/opcache/ini.xml))
- Infomaniak, `.user.ini`: PHP directives absent from the Manager can be set in `.user.ini`, which applies to its directory and below; directives marked `PHP_INI_SYSTEM`, plus `max_input_time`, `memory_limit`, `mysqli.default_socket`, cannot be set there. OPcache is not mentioned. ([FAQ 1928](https://www.infomaniak.com/en/support/faq/1928/modify-the-server-configuration-of-an-infomaniak-website))
- **(measured)** setting `opcache.enable` in `.user.ini` does not switch it on; the Manager's "PHP / Apache" tab has no OPcache control; "Extensions PHP" is a read-only list including Zend OPcache (`retro:270`, `retro:279`, `retro:286`).
- Infomaniak FAQs on site performance ([FAQ 1137](https://www.infomaniak.com/en/support/faq/1137/resolve-errors-related-to-site-overload-optimize-the-site)) and caching ([FAQ 1162](https://www.infomaniak.com/en/support/faq/1162/manage-a-cache-engine-on-wordpress-or-other-application)) do not mention OPcache for Web Hosting. `PHP_OPcache` is listed only for Cloud Server (`mut:173`, `mut:328`).
- Whether OPcache can be enabled on shared hosting: **not documented**.

### Measurement procedure

1. Upload `public/opcache-probe.php` (token-protected; delete after use):

   ```php
   <?php
   // Temporary probe. Delete after use.
   if (!hash_equals('CHANGE-ME-LONG-RANDOM', $_GET['t'] ?? '')) { http_response_code(404); exit; }
   header('Content-Type: text/plain');
   echo 'SAPI: ', PHP_SAPI, "\n";
   echo 'opcache loaded: ', var_export(extension_loaded('Zend OPcache'), true), "\n";
   echo 'ini opcache.enable (local): ', var_export(ini_get('opcache.enable'), true), "\n";
   echo 'ini opcache.enable (master): ', var_export(ini_get_all('zend opcache')['opcache.enable']['global_value'] ?? null, true), "\n";
   echo 'php_ini_loaded_file: ', var_export(php_ini_loaded_file(), true), "\n";
   echo 'php_ini_scanned_files: ', var_export(php_ini_scanned_files(), true), "\n";
   echo 'user_ini.filename: ', ini_get('user_ini.filename'), "\n";
   $s = function_exists('opcache_get_status') ? @opcache_get_status(false) : 'n/a';
   echo 'opcache_get_status: ', var_export($s, true), "\n";
   ```

2. Open it twice. If `opcache_get_status` returns an array with `opcache_enabled => true` and `opcache_statistics.hits` grows on the second call, OPcache is on.
3. Record the "master" value: if `global_value` is `0`/empty, the host disabled it at the `php.ini`/FPM pool level, which `.user.ini` cannot override (php.net quote above).
4. Ask Infomaniak support, quoting the site and the probe output: "Can `opcache.enable=1` be set for this Web Hosting site (FPM pool or `php.ini`), and if not, is it available on any Web Hosting plan or only on Cloud Server?" Record the answer with date and ticket number in `retro` §5.
5. If support enables it, re-run step 2 and measure TTFB before/after with `curl -o /dev/null -s -w '%{time_starttransfer}\n' https://aguet.dev/` (10 runs each, median).

---

## 4. Open fact: does `max_execution_time` keep counting after `fastcgi_finish_request()`?

### What the documentation and source say

- php.net: on non-Windows systems `max_execution_time` counts "only the time spent on the execution of the script itself"; "system calls, stream operations, database queries" are excluded (`mut:292`). Whether it continues after `fastcgi_finish_request` is not stated (`mut:293`).
- **(source)** `fastcgi_finish_request()` ends output, sends headers, and closes the FastCGI request; it does not touch the execution timer. [`sapi/fpm/fpm/fpm_main.c` `PHP_FUNCTION(fastcgi_finish_request)`](https://github.com/php/php-src/blob/PHP-8.5/sapi/fpm/fpm/fpm_main.c)
- **(source)** How the timer is armed, in [`Zend/zend_execute_API.c` `zend_set_timeout_ex()`](https://github.com/php/php-src/blob/PHP-8.5/Zend/zend_execute_API.c):
  - Non-thread-safe (NTS) Linux builds: `setitimer(ITIMER_PROF, …)` → `SIGPROF`, i.e. a timer of **CPU time** (user + system) consumed by the process. `sleep()` and waiting on I/O do not advance it.
  - Builds with `ZEND_MAX_EXECUTION_TIMERS`: `timer_create()` on `CLOCK_BOOTTIME` (or `CLOCK_MONOTONIC`), i.e. **wall-clock** time ([`Zend/zend_max_execution_timer.c`](https://github.com/php/php-src/blob/PHP-8.5/Zend/zend_max_execution_timer.c)). This is enabled by default only for thread-safe (ZTS) builds on Linux/FreeBSD ([`Zend/Zend.m4`](https://github.com/php/php-src/blob/PHP-8.5/Zend/Zend.m4), `--enable-zend-max-execution-timers`).
  - So by source: the timer keeps running after `fastcgi_finish_request()`; on an NTS build it counts CPU time, so deferred work that mostly waits (DB, SMTP, HTTP) consumes little of it.
- FPM's own `request_terminate_timeout` (wall clock, kills the worker) is not engaged after `fastcgi_finish_request` unless `request_terminate_timeout_track_finished = yes` (`mut:291`). Infomaniak's FPM pool values are **not documented**.
- `set_time_limit()` restarts the counter (`mut:292`) and is **(measured)** enabled on aguet's web SAPI (`retro:266`).
- Infomaniak documents neither its PHP build's thread safety nor its FPM timeouts.

### Measurement procedure

Run on a staging site of the same hosting (or aguet.dev with a long random token, then delete). The probe answers four questions: which build (NTS/ZTS), whether CPU work after `fastcgi_finish_request` is killed at `max_execution_time`, whether wall-clock waiting is, and whether something else (FPM, Apache) kills the worker.

1. Upload `public/timer-probe.php`:

   ```php
   <?php
   // Temporary probe. Delete after use.
   if (!hash_equals('CHANGE-ME-LONG-RANDOM', $_GET['t'] ?? '')) { http_response_code(404); exit; }
   $mode = ($_GET['mode'] ?? 'cpu') === 'sleep' ? 'sleep' : 'cpu';
   $limit = (int) ini_get('max_execution_time');
   $s = (int) ($_GET['s'] ?? 0);
   $target = $s > 0 ? min($s, 900) : $limit * 2 + 10; // default: well past the limit
   $log = __DIR__ . '/../storage/timer-probe.log';
   $t0 = microtime(true);
   $line = function (string $msg) use ($log, $t0, $mode) {
       $ru = getrusage();
       $cpu = $ru['ru_utime.tv_sec'] + $ru['ru_utime.tv_usec'] / 1e6 + $ru['ru_stime.tv_sec'] + $ru['ru_stime.tv_usec'] / 1e6;
       file_put_contents($log, sprintf("%s pid=%d mode=%s wall=%.1f cpu=%.1f %s\n",
           date('c'), getmypid(), $mode, microtime(true) - $t0, $cpu, $msg), FILE_APPEND | LOCK_EX);
   };
   register_shutdown_function(function () use ($line) {
       $e = error_get_last();
       $line('SHUTDOWN ' . ($e ? $e['message'] : 'clean'));
   });
   $line(sprintf('start sapi=%s zts=%d max_execution_time=%d target=%ds', PHP_SAPI, PHP_ZTS, (int) ini_get('max_execution_time'), $target));
   echo "ok, see log\n";
   $finished = function_exists('fastcgi_finish_request') ? fastcgi_finish_request() : null;
   $line('fastcgi_finish_request=' . var_export($finished, true));
   ignore_user_abort(true);
   $next = 1;
   while (microtime(true) - $t0 < $target) {
       if ($mode === 'cpu') { for ($i = 0; $i < 200000; $i++) { $x = sqrt($i); } } else { sleep(1); }
       if (microtime(true) - $t0 >= $next) { $line('alive'); $next++; }
   }
   $line('reached target');
   ```

2. Note the site's `max_execution_time` (60 s on aguet, `retro:267`). Then call each mode once, from a shell, and time the HTTP response:

   ```sh
   time curl -s 'https://STAGING/timer-probe.php?t=TOKEN&mode=cpu'
   time curl -s 'https://STAGING/timer-probe.php?t=TOKEN&mode=sleep'
   ```

   The `curl` should return in well under a second (proves `fastcgi_finish_request` released the client).
3. Wait `2 × max_execution_time + 30` s, then over SSH: `cat ~/sites/STAGING/storage/timer-probe.log`.
4. Read the log:
   - First line: `zts=0` → NTS build (CPU timer expected); `zts=1` → wall-clock timer expected.
   - `mode=cpu`: if the last `alive` is near `cpu ≈ max_execution_time` and the `SHUTDOWN` line says "Maximum execution time of N seconds exceeded", the limit **does** keep counting after `fastcgi_finish_request`, in CPU seconds. If it reaches `reached target`, it does not.
   - `mode=sleep`: on NTS, expect `reached target` with `cpu` near 0 (sleep does not count). If the log stops abruptly with **no** `SHUTDOWN` line, the worker was killed from outside PHP (FPM `request_terminate_timeout` or similar): record the `wall` value at which it stopped.
5. Optional control: repeat `mode=cpu` with `?s=` below the limit, and once with `set_time_limit(0)` added after `fastcgi_finish_request()` to confirm the toggle lifts the limit.
6. Delete `timer-probe.php` and the log. Record results (date, PHP version, `zts`, the four outcomes) in `retro` §5.1.

---

## 5. Open fact: the task planner's HTTP method, timeout and clock alignment

### What the documentation says

- [FAQ 2161](https://www.infomaniak.com/en/support/faq/2161/manage-web-hosting-task-planner): a task calls a URL at a predefined or custom interval (custom minimum 15 min on Web Hosting, 1 min on Cloud Server); the URL can be password-protected; email notifications; an execution log and a manual run are available from the action menu (`mut:315`).
- HTTP method, request timeout, user agent, source IP, retry behaviour, and whether runs are aligned to wall-clock minutes (e.g. :00/:15/:30/:45) or to the creation time: **not documented** (FAQ 2161 reviewed 2026-10-25; `mut:124-125`).
- "Password-protected": whether this means HTTP Basic auth sent by the planner is **not documented** in the FAQ text.

### Measurement procedure

1. Upload `public/planner-probe.php`, which logs every detail of the incoming call and can hold the connection open to find the planner's timeout. `sleep()` does not consume `max_execution_time` on an NTS build (§4), so a long hold is possible; `set_time_limit(0)` guards the ZTS case.

   ```php
   <?php
   // Temporary probe. Delete after use.
   if (!hash_equals('CHANGE-ME-LONG-RANDOM', $_GET['t'] ?? '')) { http_response_code(404); exit; }
   $log = __DIR__ . '/../storage/planner-probe.log';
   $t0 = microtime(true);
   $w = fn (string $m) => file_put_contents($log, sprintf("%s pid=%d +%.1fs %s\n",
       DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $t0))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
       getmypid(), microtime(true) - $t0, $m), FILE_APPEND | LOCK_EX);
   $headers = [];
   foreach ($_SERVER as $k => $v) { if (str_starts_with($k, 'HTTP_')) { $headers[$k] = $k === 'HTTP_AUTHORIZATION' ? '[present]' : $v; } }
   $w(json_encode([
       'method' => $_SERVER['REQUEST_METHOD'] ?? null,
       'uri' => preg_replace('/t=[^&]+/', 't=***', $_SERVER['REQUEST_URI'] ?? ''),
       'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
       'php_auth_user' => isset($_SERVER['PHP_AUTH_USER']) ? '[present]' : null,
       'headers' => $headers,
       'body_bytes' => strlen((string) file_get_contents('php://input')),
   ], JSON_UNESCAPED_SLASHES));
   $hold = min(max((int) ($_GET['hold'] ?? 0), 0), 900);
   if ($hold > 0) {
       @set_time_limit(0);
       ignore_user_abort(true);
       for ($i = 1; $i <= $hold; $i++) {
           sleep(1);
           echo ' '; @ob_flush(); flush();
           if (connection_aborted()) { $w("client aborted at ~{$i}s"); break; }
           if ($i % 10 === 0) { $w("holding {$i}s"); }
       }
   }
   $w('end');
   echo "ok\n";
   ```

2. **Method, headers, alignment.** In the Manager, create a task on `https://STAGING/planner-probe.php?t=TOKEN` with the custom interval of 15 min, no password. Note the exact creation time (UTC). Let it run for at least 8 runs (2 h).
   - Method, user agent, source IP, `Authorization`: first JSON line of each run.
   - Alignment: list the start timestamps (`grep method storage/planner-probe.log | cut -c1-27`). Aligned if the minutes are always in {00, 15, 30, 45} with small, stable seconds; otherwise compute the offset from the creation time. Also record the jitter (spread of the seconds field) and any missing run.
   - Repeat once with an hourly predefined interval to see whether it lands on minute 0.
3. **Password protection.** Edit the task to enable the password option; protect the probe with an `.htaccess` Basic-auth block or simply observe whether `HTTP_AUTHORIZATION`/`PHP_AUTH_USER` is `[present]` on the next run. Record the auth scheme seen.
4. **Timeout.** Create a second task on `…?t=TOKEN&hold=600` (or use the manual run). After the run:
   - The last `holding Ns` / `client aborted at ~Ns` line gives the time at which the planner closed the connection. If the log reaches `end` after 600 s, the timeout is above 600 s; repeat with `hold=900`.
   - Check the Manager's execution log for that run (status shown, any "timeout" message, recorded duration).
   - Note: Apache or the FastCGI proxy may buffer the space bytes, so `connection_aborted()` can lag; the Manager log is the cross-check.
5. Delete the tasks, the probe and the log. Record method, auth scheme, alignment (with offsets and jitter), and timeout in `retro` §5.1.
