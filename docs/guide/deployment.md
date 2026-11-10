# Deployment

The first deploy of a site and every later one. The steps are written for Infomaniak Web Hosting; [on a server](#on-a-server) the same commands run. What the host must offer is in [Hosting](hosting.md).

## Add the site

In the Infomaniak Manager, add the domain or subdomain to the Web Hosting as a new site. The Manager gives it the folder `sites/example.com` (`~/sites/example.com` over SSH), which the clone below goes into. When the domain's DNS is managed elsewhere, at Cloudflare say, point its records at the hosting.

The Manager puts its own files in that folder: `.htaccess`, `index.html`, `.infomaniak-maintenance.html` and a `.user.ini` holding its compression setting (`zlib.output_compression = On`). [Get the code](#get-the-code) removes them.

## Prepare the host

In the Infomaniak Manager, for the site:

1. Set the PHP version to 8.5.
2. On MariaDB, create a database and a user for it, and note the host, the database name, the user and the password. On SQLite, skip this step.
3. Turn on SSH access and add your SSH key.
4. Install a Let's Encrypt certificate. The Manager has no switch to force HTTPS: the starter kit's `public/.htaccess` redirects HTTP to HTTPS.

Over SSH, `php -v` must print 8.5 or later. When it prints another version, put PHP 8.5 first on the `PATH` for the session:

```bash
export PATH=/opt/php8.5/bin:$PATH
```

Composer then runs on the same PHP. Run this `export` at the start of every deploy session, or call `/opt/php8.5/bin/php` and `/opt/php8.5/bin/composer` in full. Putting it in `~/.profile` would last, but every site of the account shares that SSH user: it would change PHP and Composer for the deploys of the other sites too, some of which may still need another version.

## First deploy

### Get the code

The server reaches a private repository on GitHub with an SSH key of its own. GitHub accepts a deploy key on one repository only, and every site of the account shares the SSH user, so name the key after the site's repository rather than taking the default `id_ed25519`:

```bash
ssh-keygen -t ed25519 -f ~/.ssh/site -C "deploy example.com"
```

Add `~/.ssh/site.pub` to the site's repository on GitHub as a deploy key, read-only.

Over SSH, in the site's directory (`~/sites/example.com`), clone the repository. `git clone` needs an empty directory: remove the files the host put there first. `core.sshCommand` stays in the clone's configuration, so every later `git pull` uses the site's key:

```bash
git clone -c core.sshCommand="ssh -i ~/.ssh/site -o IdentitiesOnly=yes" git@github.com:you/site.git .
composer install --no-dev --optimize-autoloader
```

The first clone asks to confirm GitHub's host key. Compare the fingerprint it prints with [GitHub's published fingerprints](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/githubs-ssh-key-fingerprints), looking up the key type it names: the server may offer the ECDSA key rather than the ED25519 one.

A public repository needs no key: clone it from `https://github.com/you/site.git`.

### Point the site at `public/`

Right after the clone, before `.env` exists, set the site's root folder in the Manager to the clone's `public` directory (`sites/example.com/public`), so the web server serves `public/` only. A [staging copy](#a-staging-copy) is protected first.

Infomaniak's web PHP shows errors to visitors (`display_errors` on). The starter kit's `public/.user.ini` turns it off, so a fatal error before Laravel boots never shows its message and paths; PHP reads it within five minutes. A site that does not come from the starter kit adds the same file to its repository:

```ini
display_errors = Off
```

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
DB_PASSWORD='...'
```

Keep the password in single quotes: unquoted, a `#` in it starts a comment and cuts it short, and the database refuses the connection. Single quotes keep every character as it is, where double quotes expand `${...}` and reject a lone `\`. A password holding `'` cannot be quoted this way: generate another one.

On SQLite, keep `DB_CONNECTION=sqlite`: `kami:install` creates `database/database.sqlite`.

Once `php artisan optimize` has run, in [Install Kami](#install-kami) or a later deploy, the configuration is cached: an edit to `.env` changes nothing until `php artisan optimize` runs again.

### Front assets

The server never runs Node. Build the site's front assets on your machine (`npm ci && npm run build`), or download the `build` artifact the starter kit's CI workflow uploads, then copy `public/build/` next to the code:

```bash
rsync -a public/build/ you@host:sites/example.com/public/build/
```

Kami's own admin assets need nothing: `kami:install` copies them.

### Install Kami

Over SSH, run the first install in the terminal, so that it asks for the first administrator's email, name and password:

```bash
php artisan kami:install
php artisan optimize
```

`kami:install` stops on any environment error, with its fix ([Installation](installation.md#checking-the-environment)). `optimize` caches the configuration, routes, events and views. A first install from a deploy script, with no terminal, takes `--no-interaction --email=... --name=...` and prints a one-time link to set the password, or reads it from the `KAMI_ADMIN_PASSWORD` environment variable, never from an argument ([Installation](installation.md#the-first-user)). Sign in at `https://example.com/admin`.

Run `php artisan kami:doctor` once the site answers, and once more after the first visit, so that it sees the web's PHP too.

### The web PHP

Over SSH, `kami:doctor` runs under the command line's PHP, whose settings and extensions may differ from the web's ([Hosting](hosting.md#infomaniak-shared-hosting)). So each Tick started by a visit or the tick URL writes what the web's PHP looks like to `storage/framework/kami/web-environment.json`, rewritten when it changes. `kami:doctor` prints it under **Web PHP**, with the time it was last seen: the SAPI, the PHP version, `memory_limit`, `max_execution_time`, `disable_functions`, how the extensions differ from the command line's, and the image formats the web encodes. It fails on a web PHP below the floor or lacking a required extension, as it does for the command line's, and warns while no web request has run a Tick yet: visit the site, then run it again.

### Scheduled tasks

The starter kit's `routes/console.php` calls `Kami::schedule()`, which declares the sitemap rebuild and Kami's pruning; a site of your own adds that line. `php artisan kami:tick` runs them when overdue, over SSH ([Scheduler](scheduler.md)).

## Later deploys

Over SSH, in the site's directory:

```bash
php artisan down
git pull --ff-only &&
  composer install --no-dev --optimize-autoloader &&
  php artisan kami:install --no-interaction &&
  php artisan optimize &&
  php artisan up
```

When `php -v` prints another version than 8.5, put PHP 8.5 first on the `PATH` beforehand, as in [Prepare the host](#prepare-the-host). `--ff-only` makes the deploy fail rather than merge on the server when its branch has diverged from GitHub's. Copy `public/build/` as in [Front assets](#front-assets) when the front changed. Updating Kami is the same deploy, after `composer update psyao/kami` in the site's repository: there is no separate upgrade command.

`kami:install` also moves Paths to follow the code: a new Prefix or Locale takes effect there ([Routing and rendering](routing-and-rendering.md#after-a-deploy)). It creates the Entry of each new [Singleton and Global Set](content-types.md#singletons) too, and never touches an existing one. It rewrites `public/robots.txt` from the [indexable switch](seo.md#robotstxt), unless the file is the site's own; it no longer rebuilds the sitemap, which a Tick does hourly ([Scheduler](scheduler.md)). Before writing any of that, it fails on [content model drift](content-types.md#content-model-drift), right after running your migrations.

When a step fails, `kami:install` included, the deploy stops before `php artisan up` and the site stays in maintenance mode: fix the problem it prints, then run the chained command again, from `git pull`.

## Measuring page speed

After a deploy, measure the server time of a page from the host itself, over SSH:

```bash
php artisan kami:bench
php artisan kami:bench /blog
```

It calls the page on the Site URL ten times, served from the [page cache](page-cache.md), then ten times with `?kami-bench=1` to `10`, which the page cache never serves, and prints the median of each. The time is the server's: from the end of the TLS handshake to the first byte of the response, with no network between you and the host. Over plain `http`, it also counts the connection, a local one. It warns above the budgets, 30 ms cached and 200 ms uncached, and never fails, so a deploy script can run it. A page that does not answer 200, a redirect included, stops the measure: bench the address the page lives at. So does a host that cannot reach its own public address, as some servers behind NAT cannot: it prints why and measures nothing, the same on shared hosting and on a server.

`kami:doctor` shows the last result, with the address and the time it was measured.

Behind a staging password, pass its user: `kami:bench --user=preview` asks for the password, never as an argument, and signs in with Digest. Apache answers the first request with a 401 before the page, a round trip on the host itself that the figure may include.

## Media files

After migrating a site's images or importing Media, generate their Conversions before visitors ask for them, then delete those no longer served once you change the presets:

```bash
php artisan kami:media:warm
php artisan kami:media:prune
```

Both run over SSH and can be started again when cut short: `warm` skips the files already written, `prune` deletes only what no Media item serves. Neither is needed for the site to work ([Media](media.md#pre-generating-conversions)).

## A staging copy

A staging copy runs the same steps on its own subdomain, with its own database and `.env`. The [indexable switch](seo.md#indexable-switch) keeps it out of search engines with nothing to configure: outside `APP_ENV=production` it is off, so `kami:install` writes a `robots.txt` that disallows everything and every page carries `X-Robots-Tag: noindex, nofollow`. A password keeps people out as well, for a site not yet launched: put the whole site behind one, and measure it with `kami:bench --user=` ([Measuring page speed](#measuring-page-speed)). Protect it before its root folder points at `public/`, or the subdomain answers publicly, with Laravel's error page until Kami is installed.

On Infomaniak, the setting is **Web Hosting → Security → Folder protection**, with Digest authentication, which keeps the password out of plain HTTP before the redirect to HTTPS. Protect the clone's directory (`sites/staging.example.com`), not `public/`: the protection writes `.htaccess` and `.htdigest` into the folder it protects, and in `public/` it would write into the site's own `public/.htaccess` and break the next `git pull`. Apache applies the parent directory's `.htaccess` to `public/`, and `.htdigest`, outside `public/`, is never served. Then keep the two files out of `git status`, in the clone:

```bash
printf '/.htaccess\n/.htdigest\n' >> .git/info/exclude
```

## On a server

The same commands run over SSH. Point the web server's document root at `public/`. On nginx, send every request that matches no file to `index.php`, as Laravel's [deployment guide](https://laravel.com/docs/13.x/deployment#nginx) shows, and give `public/media` the headers [Hosting](hosting.md#nginx) lists. Run `php artisan kami:tick` from cron every minute, in place of Laravel's `schedule:run` ([Scheduler](scheduler.md#on-a-server)); Kami needs no queue worker or daemon: the Tick works through the `database` queue ([Scheduler](scheduler.md#the-queue)).
