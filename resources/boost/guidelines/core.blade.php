## Kami

Kami (`psyao/kami`) is a CMS package installed into this Laravel application. Its code lives in `vendor/psyao/kami` and changes only through `composer update psyao/kami`.

- Kami's admin is `Psyao\Kami\Admin\KamiPlugin` on the application's own Filament panel; Kami ships no panel of its own.
- The panel's `defaultAvatarProvider()` is `Psyao\Kami\Admin\InitialsAvatarProvider`, which draws avatars on the server, so Users' names stay on the site.
- `php artisan kami:install --no-interaction` is the install, deploy and upgrade step, safe to rerun: run it after every deploy, an update of Kami included, then `php artisan optimize`. Run `optimize` again after any change to `.env`: a cached configuration no longer reads it.
- `php artisan kami:doctor` checks the environment: run it after any change to PHP, its extensions, `.env` or the database. It sees the command line's PHP, which on shared hosting differs from the web's.
- The site runs on shared hosting: work happens inside the web request or over SSH, with the database and `file` stores, and front assets are built before the deploy and copied with the code.
- Dates are stored and computed in UTC: Kami sets `app.timezone` to UTC, and the application's code keeps it.
- Kami's guides are in `vendor/psyao/kami/docs/guide/`, its decision records in `vendor/psyao/kami/docs/adr/`. Activate the `kami-development` skill for any task that installs, configures, deploys or checks Kami.
