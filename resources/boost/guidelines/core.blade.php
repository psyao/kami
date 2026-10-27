## Kami

Kami (`psyao/kami`) is a CMS package installed into this Laravel application. Its code lives in `vendor/psyao/kami` and changes only through `composer update psyao/kami`.

- Kami's admin is `Psyao\Kami\Admin\KamiPlugin` on the application's own Filament panel; Kami ships no panel of its own.
- Avatars in the panel come from `Psyao\Kami\Admin\InitialsAvatarProvider`, drawn on the server: Users' names stay off third-party services.
- `php artisan kami:install --no-interaction` is the install, the deploy and the upgrade step, safe to rerun; run it after every deploy and every update of Kami.
- `php artisan kami:doctor` checks the environment; run it after any change to PHP, its extensions, `.env` or the database. It sees the command line's PHP, which on shared hosting differs from the web's.
- The site runs on shared hosting: work happens inside the web request or over SSH, with no cron, queue worker, Redis or `exec`/`proc_open` on the web, and front assets built before the deploy, never by Node on the server.
- Dates are stored and computed in UTC: Kami sets `app.timezone` to UTC, so the application's code keeps UTC too.
- Kami's tables are the ones prefixed `kami_`; the application's own tables use other names.
- Kami's guides are in `vendor/psyao/kami/docs/guide/`, its decision records in `vendor/psyao/kami/docs/adr/`. Activate the `kami-development` skill for any task that installs, configures, deploys or checks Kami.
