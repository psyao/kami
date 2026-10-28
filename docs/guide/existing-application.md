# Existing application

Kami added to a Laravel application that already does other things. Kami takes nothing over: it adds its admin to your own Filament panel and leaves your routes, models and tables alone. A new site starts from the starter kit instead ([Installation](installation.md#creating-a-site)).

## Requirements

- Laravel 13.32 or later and Filament 5.10 or later
- Everything [Installation](installation.md#requirements) lists

## Install the package

Until Kami's first release on Packagist, Composer installs it from GitHub:

```bash
composer config repositories.kami vcs https://github.com/psyao/kami
composer require psyao/kami:dev-main
```

## Add Kami to your panel

Kami ships no panel of its own. Add its plugin, and its local avatar provider, to your panel provider as [Installation](installation.md#installing-and-deploying) shows. An application without a panel creates one first:

```bash
php artisan make:filament-panel admin
```

Who may sign in stays your `User` model's `canAccessPanel()`: Kami does not touch it.

## Add Kami's security headers

Kami's own pages carry its Content Security Policy whatever you do. Your panel and your own pages get it only once you add the middleware, to the panel's `->middleware([...])` and to the `web` group, as [Security](security.md#your-own-pages) shows. Check first that your own pages load no inline script or style: the policy blocks them.

## Give your User model Kami's Roles

The model your panel signs Users in with implements `KamiUser`, with the `HasKamiRoles` trait, as [Users and roles](users-and-roles.md#the-user-model) shows. Its Roles live in Kami's own `kami_role_user` table: your `users` table is left alone. Users with no Kami Role, such as your own staff, see nothing of Kami.

## Run `kami:install`

```bash
php artisan kami:install --no-interaction
```

Until a panel carries `KamiPlugin` and its User model implements `KamiUser`, it stops before changing anything and prints the lines to add. It never edits your files. Run it on every deploy, as [Deployment](deployment.md#later-deploys) shows.

## What Kami changes in your application

- **The time zone.** Kami sets `app.timezone` to UTC, whatever `config/app.php` says, so dates are stored and computed in UTC.
- **Its configuration.** `config/kami.php` holds the Sites and the Roles. Publish it to change them:

  ```bash
  php artisan vendor:publish --tag=kami-config
  ```

- **Filament's assets.** `kami:install` copies them to `public/`, as `php artisan filament:assets` does.

Kami adds no route or scheduled task to your application until you ask for them, and applies no middleware to your routes or your panel: it only names the `kami` middleware group and the `kami.security-headers` alias.
