# Installation

Creating a site with Kami, checking its environment and installing Kami on it. Deploying it is in [Deployment](deployment.md), adding Kami to an application you already have in [Existing application](existing-application.md).

## Requirements

Kami runs on PHP 8.5 or later, on the command line and on the web. On shared hosting the two often run different PHP builds, so check both.

PHP extensions:

- **Required:** ctype, dom, fileinfo, filter, hash, iconv, intl, json, libxml, mbstring, openssl, pcre, session, tokenizer, xmlreader, zip, and the PDO driver of your database (`pdo_sqlite`, `pdo_mysql` or `pdo_pgsql`).
- **Recommended:** gd or imagick. Kami runs without either, but will need one to resize images.

### Writable directories

The user PHP runs as must be able to write to `storage/` and `bootstrap/cache/`. Kami keeps its own files under `storage/framework/kami/`, which carries its own `.gitignore` so git never sees them.

### Database

Kami supports SQLite, MySQL 8.4, MariaDB 10.11 and PostgreSQL 15 or later. The connection comes from the `DB_*` settings in `.env`. Keep `DB_PASSWORD` in single quotes (`DB_PASSWORD='...'`): unquoted, a `#` in the password starts a comment and cuts it short, and the database refuses the connection. Single quotes keep every other character as it is; a password holding `'` needs replacing. Once `php artisan optimize` (or `config:cache`) has run, the configuration is cached and an edit to `.env` changes nothing until it is cached again.

On SQLite, the database file need not exist: `kami:install` creates it, as on a first deploy from git where `database.sqlite` is ignored. Its directory must exist and be writable by the user PHP runs as.

## Creating a site

A new site starts from the starter kit, a Laravel application already wired for Kami: its panel carries `KamiPlugin` and the local avatar provider. On your machine, with PHP, Composer, Node and the [Laravel installer](https://laravel.com/docs/13.x/installation):

```bash
laravel new site --using=psyao/kami-starter
cd site
php artisan kami:install
```

The site owns the starter kit's code from then on: Kami's updates come with `composer update psyao/kami`, never from the starter kit.

Accept Laravel Boost when the installer offers it: the starter kit's `boost.json` selects Kami's guideline and `kami-development` skill, and Filament's skill, so the site's coding agent knows Kami. Declined, `php artisan boost:install` sets it up later.

### The first User

On a site where no User may manage Users yet, `kami:install` ends by creating the first administrator, who can sign in to the admin at `/admin`. In a terminal it asks for the email, the name and the password; the password is checked against your application's `Password::defaults()`. The email and the name can come as options instead:

```bash
php artisan kami:install --email=ada@example.com --name=Ada
```

The password is never an option, so it stays out of the shell history and the process list. Without a terminal (`--no-interaction`, a deploy script, CI), `kami:install` reads it from the `KAMI_ADMIN_PASSWORD` environment variable, and fails, naming what to pass, when the email, the name or the password is missing. Set it in the process's environment, from a CI secret or in your shell without echoing it, never in `.env`, which is no longer read once the configuration is cached:

```bash
read -rs KAMI_ADMIN_PASSWORD && export KAMI_ADMIN_PASSWORD
php artisan kami:install --no-interaction --email=ada@example.com --name=Ada
unset KAMI_ADMIN_PASSWORD
```

The new User gets the `administrator` Role ([Users and roles](users-and-roles.md#the-first-administrator)). An email that belongs to an existing User fails: `kami:install` does not grant a Role to an existing User yet. Once a User holds a Role that grants `kami.users.manage`, the step does nothing, and later deploys need neither the options nor the variable.

## Checking the environment

```bash
php artisan kami:doctor
```

`kami:doctor` lists every problem it finds with its severity, the fix and the section of this guide that explains it:

- an **error** means the site cannot run: the command exits with 1;
- a **warning** means a doubtful setting: the command exits with 0, unless you pass `--strict`, which fails on warnings too;
- an **information** is worth knowing and never fails the command.

It checks the PHP it runs under: on shared hosting, that is the command line's, which may differ from the web's.

Run it after any change to the environment, and with `--strict` in your CI if warnings should fail it. Each run writes its result to `storage/framework/kami/doctor.json`.

## Installing and deploying

The starter kit's panel already carries the lines below. In another application, add Kami to your own Filament panel, in its panel provider:

```php
use Psyao\Kami\Admin\KamiPlugin;

->plugin(KamiPlugin::make())
```

Filament's default avatars send the initials of your Users to `ui-avatars.com`. `InitialsAvatarProvider` draws them on your server instead. Set it on the same panel:

```php
use Psyao\Kami\Admin\InitialsAvatarProvider;

->defaultAvatarProvider(InitialsAvatarProvider::class)
```

`KamiPlugin` does not set it for you: it changes nothing panel-wide, so another plugin or your own provider stays your choice. `kami:doctor` warns about a panel carrying `KamiPlugin` that keeps Filament's default.

Add Kami's admin security headers at the end of the panel's `->middleware([...])`, and Kami's front headers to your own pages, as [Security](security.md) shows:

```php
'kami.security-headers:admin',
```

Then run, on the first install and on every deploy:

```bash
php artisan kami:install --no-interaction
```

There is no separate upgrade command: `kami:install` is safe to run again and changes nothing when the site is up to date. It needs no terminal, so it runs as is in a deploy script or CI; only the first install without one needs the administrator's details ([The first User](#the-first-user)). On shared hosting, run it over SSH or from your deploy script: it needs no cron, worker or Node, only the command-line PHP that `kami:doctor` checks. In order, it:

1. stops on any error `kami:doctor` reports about the environment, before touching the database (warnings do not stop it: run `kami:doctor` to see them);
2. stops while no panel carries `KamiPlugin`, printing the `KamiPlugin` lines above;
3. runs the migrations (`migrate --force`);
4. publishes Filament's assets to `public/`, so the server needs no Node;
5. creates the first administrator while no User may manage Users ([The first User](#the-first-user)).

It exits with 1 when any step fails, so the deploy fails with it. `kami:install` never writes to your application's files: it only adds Filament's assets under `public/`. On a server, `php artisan optimize` follows it ([Deployment](deployment.md#later-deploys)).
