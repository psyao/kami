# Deployment

The first deploy of a site and every later one. The steps are written for Infomaniak Web Hosting; [on a server](#on-a-server) the same commands run. What the host must offer is in [Hosting](hosting.md).

## Prepare the host

In the Infomaniak Manager, for the site:

1. Set the PHP version to 8.5.
2. On MariaDB, create a database and a user for it, and note the host, the database name, the user and the password. On SQLite, skip this step.
3. Turn on SSH access and add your SSH key.
4. Install a Let's Encrypt certificate and force HTTPS.

Over SSH, `php -v` must print 8.5 or later. When it prints another version, put PHP 8.5 first on the `PATH`, in `~/.profile` so later sessions keep it:

```bash
export PATH=/opt/php8.5/bin:$PATH
```

Composer then runs on the same PHP.

## First deploy

### Get the code

Over SSH, in the site's directory (`~/sites/example.com`), clone the site's repository. `git clone` needs an empty directory: remove the files the host put there first.

```bash
git clone git@github.com:you/site.git .
composer install --no-dev --optimize-autoloader
```

The server reaches GitHub with its own SSH key. Create it once, then add `~/.ssh/id_ed25519.pub` to the site's repository on GitHub as a deploy key, read-only:

```bash
ssh-keygen -t ed25519
```

A public repository needs no key: clone it from `https://github.com/you/site.git`.

### Configure

```bash
cp .env.example .env
php artisan key:generate
```

Then set, in `.env`:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://example.com
```

On MariaDB, add the database from [Prepare the host](#prepare-the-host):

```dotenv
DB_CONNECTION=mariadb
DB_HOST=xxxx.myd.infomaniak.com
DB_PORT=3306
DB_DATABASE=xxxx_site
DB_USERNAME=xxxx_site
DB_PASSWORD=...
```

On SQLite, keep `DB_CONNECTION=sqlite`: `kami:install` creates `database/database.sqlite`.

Infomaniak's web PHP shows errors to visitors (`display_errors` on). Add `public/.user.ini` to the site's repository, so a fatal error before Laravel boots never shows its message and paths:

```ini
display_errors = Off
```

PHP reads it within five minutes.

### Front assets

The server never runs Node. Build the site's front assets on your machine (`npm ci && npm run build`), or download the `build` artifact the starter kit's CI workflow uploads, then copy `public/build/` next to the code:

```bash
rsync -a public/build/ you@host:sites/example.com/public/build/
```

Kami's own admin assets need nothing: `kami:install` copies them.

### Point the site at `public/`

In the Manager, set the site's root folder to the clone's `public` directory (`sites/example.com/public`), so the web server serves `public/` only.

### Install Kami

```bash
php artisan kami:install --no-interaction
php artisan optimize
```

`kami:install` stops on any environment error, with its fix ([Installation](installation.md#checking-the-environment)). `optimize` caches the configuration, routes, events and views: run it again after any change to `.env`, which a cached configuration no longer reads. Then create the first User, who can sign in to the admin:

```bash
php artisan make:filament-user
```

It asks for the name, the email and the password: answer at its prompts rather than with `--password`, which would leave the password in the shell history. Sign in at `https://example.com/admin`.

Run `php artisan kami:doctor` once the site answers. It sees the command line's PHP, not the web's ([Hosting](hosting.md#infomaniak-shared-hosting)).

## Later deploys

Over SSH, in the site's directory:

```bash
php artisan down
git pull &&
  composer install --no-dev --optimize-autoloader &&
  php artisan kami:install --no-interaction &&
  php artisan optimize &&
  php artisan up
```

Copy `public/build/` as in [Front assets](#front-assets) when the front changed. Updating Kami is the same deploy, after `composer update psyao/kami` in the site's repository: there is no separate upgrade command.

When a step fails, `kami:install` included, the deploy stops before `php artisan up` and the site stays in maintenance mode: fix the problem it prints, then run the chained command again, from `git pull`.

## A staging copy

A staging copy runs the same steps on its own subdomain, with its own database and `.env`. Kami has no indexing switch yet, so keep a staging copy out of search engines by putting the whole site behind a password (HTTP authentication) in your host's control panel.

## On a server

The same commands run over SSH. Point the web server's document root at `public/`. On nginx, send every request that matches no file to `index.php`, as Laravel's [deployment guide](https://laravel.com/docs/13.x/deployment#nginx) shows. Kami needs no cron, queue worker or daemon there either.
