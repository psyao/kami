# Psyao Kami

A Laravel and Filament CMS for developers, at home on shared hosting.

Psyao Kami, Kami for short, is a Composer package. Its admin is a plugin on your own Filament panel, and a site's content model is PHP code: Kami adds what a site needs without taking over the application. Everything runs on shared hosting with no cron, queue worker, Redis or Node on the server, and nothing essential depends on a remote service.

Kami is in early development: so far it covers installation, the environment check and the admin's wiring.

## Requirements

- PHP 8.5 or later, with the extensions listed in [Installation](docs/guide/installation.md#requirements)
- Laravel 13.32 or later and Filament 5.10 or later
- SQLite, MySQL 8.4, MariaDB 10.11 or PostgreSQL 15 or later

## Install

A new site starts from the starter kit:

```bash
laravel new site --using=psyao/kami-starter
cd site
php artisan kami:install
php artisan make:filament-user
php artisan serve
```

The admin answers at `/admin`. To add Kami to an application you already have, see [Existing application](docs/guide/existing-application.md).

## Documentation

- [Installation](docs/guide/installation.md): requirements, creating a site, `kami:doctor` and `kami:install`
- [Existing application](docs/guide/existing-application.md): adding Kami to a Laravel application and its panel
- [Content types](docs/guide/content-types.md): declaring a type and its Fields, writing and reading Entries
- [Multilingual](docs/guide/multilingual.md): Locales, Translations, Shared Fields and Fallback with `lang`
- [Publishing](docs/guide/publishing.md): Draft, Scheduled and Published, validation, slugs, Content events, and why `SaveEntry` does not authorise
- [Users and roles](docs/guide/users-and-roles.md): `KamiUser`, Roles in `config/kami.php`, Permissions and strict authorization
- [Routing and rendering](docs/guide/routing-and-rendering.md): `Kami::routes()`, Paths and a type's `render()`
- [Security](docs/guide/security.md): the front and admin Content Security Policies, extending them, and the options that weaken them
- [Hosting](docs/guide/hosting.md): what Kami needs from shared hosting and from a server
- [Deployment](docs/guide/deployment.md): the first deploy and every later one, on Infomaniak shared hosting or a server
- [Decision records](docs/adr/): why Kami is built the way it is

Contributing to Kami itself: [Development](https://github.com/psyao/kami/blob/main/docs/development.md).

## Licence

MIT, see [LICENSE.md](LICENSE.md).
