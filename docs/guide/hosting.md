# Hosting

Kami runs on shared hosting with nothing more than PHP, a database and SSH. One code path serves shared hosting and a server: a server only adds speed.

## What the host needs

- PHP 8.5 or later, on the command line and on the web, with the extensions [Installation](installation.md#requirements) lists. The two often run different PHP builds: check both.
- One of the [supported databases](installation.md#database).
- SSH access with Composer, to run `composer install` and `php artisan kami:install` on each deploy.
- A document root on the site's `public/` directory, and HTTPS.
- Write access to `storage/` and `bootstrap/cache/` for the user PHP runs as.

## What Kami does not need

- **No cron.** Kami registers no scheduled task.
- **No queue worker.** Kami dispatches no job.
- **No Redis, Memcached or other daemon.** The starter kit uses the `file` cache store and the `database` session driver.
- **No Node on the server.** Kami's admin assets ship precompiled and `kami:install` copies them to `public/`. The site's own front assets are built before the deploy: see [Deployment](deployment.md#front-assets).
- **No `exec` or `proc_open` on the web.** Shared hosts often disable them; Kami never calls them.

## Shared hosting and a server

| Subject | Shared hosting (Infomaniak Web Hosting) | Server (Cloud Server or VPS) |
| --- | --- | --- |
| **Install and deploy** | `kami:install` over SSH, under the command line's limits (no time limit on Infomaniak) | The same |
| **Admin assets** | Precompiled in the package, copied to `public/` by `kami:install` | The same |
| **Front assets** | Built by the site in CI or on the developer's machine, then copied with the code | The same |
| **Cache and sessions** | `file` cache, `database` sessions, as the starter kit sets them | The same; Redis or Memcached possible, never required |
| **Dates** | Kami sets the application's time zone to UTC, whatever the host's default (`Europe/Zurich` on Infomaniak) | The same |
| **PHP settings** | The web and the command line differ: on Infomaniak, the web has 60 s and 640 MB, the command line no time limit and 1 GB | Your own `php.ini` |

## Infomaniak shared hosting

Facts measured on Infomaniak Web Hosting, on PHP 8.5, that change what a site sees:

- **The command line is not the web.** Over SSH, PHP reads its own settings: `exec` and `proc_open` are allowed there and disabled on the web. `kami:doctor` checks the PHP it runs under, which is the command line's.
- **OPcache is off on the web** and cannot be turned on. Every request compiles Laravel and Filament again, so the admin is slower than on a server. Nothing in Kami depends on it.
- **`display_errors` is on on the web.** A fatal error before Laravel boots shows its message, paths included, to visitors. Turn it off as [Deployment](deployment.md#configure) shows.
- **The database is remote.** Every query pays network latency.
- **Every site of an account runs as the same user,** the SSH user, so `storage/` and `bootstrap/cache/` are writable once cloned, and so are the other sites' files.
- **There is no Node** on the SSH `PATH`.
