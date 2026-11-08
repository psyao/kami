# Existing application

Kami added to a Laravel application that already does other things. Kami takes nothing over: it adds its admin to your own Filament panel and leaves your routes, models and tables alone. A new site starts from the starter kit instead ([Installation](installation.md#creating-a-site)).

## Requirements

- Laravel 13.32 or later and Filament 5.10.2 or later
- Everything [Installation](installation.md#requirements) lists

## Install the package

```bash
composer require psyao/kami
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

Until a panel carries `KamiPlugin` and its User model implements `KamiUser` and the two MFA contracts ([The user model](users-and-roles.md#the-user-model)), it stops before changing anything and prints the lines to add. It never edits your files. Run it on every deploy, as [Deployment](deployment.md#later-deploys) shows.

On its first run it makes sure someone can manage Users. Give it the email of one of your existing Users, with `--email` or at its prompt, and that User gets the `administrator` Role and keeps their password, after a confirmation in a terminal. An unknown email creates a new User with the Role, from `--email` and `--name` with a one-time link to set the password or the `KAMI_ADMIN_PASSWORD` environment variable, or at its prompts ([Installation](installation.md#the-first-user)).

## Serve cached pages before Laravel boots

Add one line to `public/index.php`, right after Laravel's maintenance check, so a cached page is sent before Laravel boots:

```php
// Serve a page from Kami's page cache...
(require __DIR__ . '/../vendor/psyao/kami/resources/page-cache.php')(__DIR__ . '/../storage');
```

It only answers what Kami has cached, its own pages, and lets every other request through. Without it the page cache still works, once Laravel has booted ([Page cache](page-cache.md#serving-before-laravel-boots)). Leave it out when you [keep your members signed in](#keep-your-members-signed-in): nothing is cached then, and the line would read a file on every request for nothing.

## Keep your members signed in

Kami's pages run without your `web` group: no session, no cookie, so the page cache can serve them. When your layout shows who is signed in, with `@auth` or a logout form, a signed-in member would appear signed out on them. Mount Kami's pages in your session instead, in `routes/web.php`:

```php
Kami::routes(session: true);
```

Kami's pages then run in your `web` group, with your session, cookies and CSRF. Conversions, Preview links and the sitemap stay out of it.

- **The cost.** A page in a session belongs to one visitor, so the page cache never stores it: each request boots Laravel and renders the page, within the uncached budget of 200 ms rather than the cached 30 ms ([Measuring](deployment.md#measuring-page-speed)). It costs the same on shared hosting and on a server; on shared hosting, without OPcache, the whole budget goes to it. `Kami::remember()` still caches fragments.
- **The Content Security Policy.** Your layout's scripts must fit Kami's front policy, which has no nonce so that a page can be cached ([Security](security.md#kamis-pages)). Livewire's `csp_safe` build with Alpine's CSP build runs under it, as long as your Alpine expressions stay within Alpine's CSP subset: no arrow functions, template literals, spread or globals, with complex logic moved to `Alpine.data()`. An inline `<script>` or `<style>`, such as `@livewireStyles` or a theme script, goes into `security.csp` as its `sha256` hash ([Extending the policy](security.md#extending-the-policy)). Otherwise add `'unsafe-eval'` or `'unsafe-inline'`, which `kami:doctor` reports as a weakening ([Weakening options](security.md#weakening-options)).

## Localise your error pages

Call `Kami::exceptions($exceptions)` first in `bootstrap/app.php`'s `withExceptions()`, so your error views render in the Locale of the URL, `/en/…` in English, on your routes as on Kami's. Kami ships no error views and leaves yours as they are ([Error pages](routing-and-rendering.md#error-pages)).

## What Kami changes in your application

- **The time zone.** Kami sets `app.timezone` to UTC, whatever `config/app.php` says, so dates are stored and computed in UTC.
- **Its configuration.** `config/kami.php` holds the Sites and the Roles. Publish it to change them:

  ```bash
  php artisan vendor:publish --tag=kami-config
  ```

- **Filament's assets.** `kami:install` copies them to `public/`, as `php artisan filament:assets` does.
- **Dashboard widgets.** `KamiPlugin` adds three widgets to the panel's dashboard, after your own: **Recently edited**, **Scheduled** and **Needs attention**, each shown only to a User allowed to see it. A dashboard page of yours that lists its own widgets in `getWidgets()` decides whether they appear. They cannot be turned off otherwise yet.
- **Global search on Kami's panel.** Filament's own search leaves Kami's types out, so the panel carrying `KamiPlugin` gets Kami's, which searches Entries first and then your Resources, as Filament's does. A provider of your own, or `->globalSearch(false)`, wins; your other panels keep Filament's.

- **The sitemap.** Kami writes `public/sitemaps/` and, unless one of your routes answers `/sitemap.xml`, `public/sitemap.xml`. It registers `kami:sitemap` `hourly()` in Laravel's scheduler, which rebuilds them when stale ([SEO](seo.md#sitemap)).

Kami adds no route to your application until you call `Kami::routes()`, and applies no middleware to your routes or your panel: it only names the `kami` middleware group and the `kami.security-headers` alias.

## Your own sitemap

When your application answers `/sitemap.xml` itself, Kami writes no index over it, and `kami:doctor` says so. List Kami's per-Locale files in your index with `Kami::sitemapUrls()`, which returns their absolute URLs ([SEO](seo.md#host-routes)).

## Testing your site

Kami answers the URLs your routes leave, and reads `kami_paths` to do so: a test reaching it needs the tables, and `RefreshDatabase` runs Kami's migrations with yours. Without it, Laravel's default `ExampleTest` fails with `no such table: kami_paths` once the `welcome` route is gone; with it, `/` answers 404 until something is published there:

```php
use App\Content\Home;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psyao\Kami\Content\Actions\SaveEntry;

uses(RefreshDatabase::class);

it('answers at the home page', function () {
    app(SaveEntry::class)->handle(Home::class, ['title' => 'Home', 'status' => 'published']);

    $this->get('/')->assertOk();
});
```
