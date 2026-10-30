## Kami

Kami (`psyao/kami`) is a CMS package installed into this Laravel application. Its code lives in `vendor/psyao/kami` and changes only through `composer update psyao/kami`.

- Kami's admin is `Psyao\Kami\Admin\KamiPlugin` on the application's own Filament panel; Kami ships no panel of its own.
- The panel's `defaultAvatarProvider()` is `Psyao\Kami\Admin\InitialsAvatarProvider`, which draws avatars on the server, so Users' names stay on the site.
- Every write to an Entry goes through `Psyao\Kami\Content\Actions\SaveEntry`, never through `save()` on a Kami model: it validates, keeps Paths and dispatches Content events. It checks no Permission, so code calling it from a request checks one first.
- Entries are read through `Psyao\Kami\Content\Entry`, whose queries return visible Entries only; `withUnpublished()` is the deliberate way to reach Drafts and Scheduled ones.
- A Field handle is snake_case and stays clear of the names in `RESERVED_HANDLES` on `Psyao\Kami\Content\Fields\Field` and of `Entry`'s methods.
- Labels of types and Fields live in `lang/<locale>/kami-types.php`, for every Locale; a declaration holds none.
- Every schema change of a type (a new type, a Field added, removed or retyped) goes through `php artisan kami:make:migration`, whose migration is read and committed before `kami:install` runs it.
- `Psyao\Kami\Support\SecurityHeaders` is appended to the `web` group and `'kami.security-headers:admin'` ends the panel's middleware. Front markup loads scripts and styles from built files, since the policy blocks inline ones.
- Access checks target Permissions, such as `kami.entries.{type}.publish` or `kami.users.manage`, through Laravel's Gate, never a Role. Roles are declared in `config/kami.php`, and the panel's User model implements `Psyao\Kami\Users\KamiUser`.
- `php artisan kami:install --no-interaction` is the install, deploy and upgrade step, safe to rerun: run it after every deploy, an update of Kami included, then `php artisan optimize`. Run `optimize` again after any change to `.env`: a cached configuration no longer reads it.
- `kami:install` also moves Paths to follow a changed Prefix or a new Locale, and fails on a Path clash, naming both Entries: change one slug, then rerun it. A first install without a terminal takes `--email`, `--name` and the `KAMI_ADMIN_PASSWORD` environment variable.
- `php artisan kami:doctor` checks the environment: run it after any change to PHP, its extensions, `.env` or the database. It sees the command line's PHP, which on shared hosting differs from the web's.
- The site runs on shared hosting: work happens inside the web request or over SSH, with the database and `file` stores, and front assets are built before the deploy and copied with the code.
- Dates are stored and computed in UTC: Kami sets `app.timezone` to UTC, and the application's code keeps it.
- Kami's guides are in `vendor/psyao/kami/docs/guide/`, its decision records in `vendor/psyao/kami/docs/adr/`. Activate the `kami-development` skill for any task that touches Kami: content types, Entries, Locales, rendering, security headers, Roles, install, deploy or checks.
