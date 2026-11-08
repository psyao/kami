# Routing and rendering

How a visitor reaches an Entry: Kami answers each Entry's Path with one route, and its Content Type turns the Entry into a page. Declaring types and writing Entries is in [Content types](content-types.md).

## Kami's route

Call `Kami::routes()` in `routes/web.php`:

```php
use Psyao\Kami\Facades\Kami;

Route::get('/contact', ContactController::class);

Kami::routes();
```

It registers a single fallback route, so your routes always win: `/contact` stays yours even if an Entry claims it, and an address nobody claims answers your application's 404. Until you call it, Kami adds no route at all.

Kami's route runs in its own `kami` middleware group, without the `web` group's session, cookies and CSRF protection, even when it is called inside that group. A page then sets no cookie. The group also sends Kami's Content Security Policy and security headers ([Security](security.md)).

## Paths

Each Entry has a Path: its type's Prefix, then its slug, as `articles/hello-world`. It is stored lowercase, without leading or trailing slash. A request is matched to it with one indexed read, byte for byte on every engine: `/café` and `/cafe` are two addresses.

The default Locale's Paths start at the root; another Locale's sit under its code, as `/en/articles/hello-world`. See [Multilingual](multilingual.md).

A [Singleton](content-types.md#singletons)'s Path is its Prefix, and a [type with no URL](content-types.md#types-with-no-url) has none.

An Entry answers only while it is visible. A Draft, an Entry whose publication date is still to come, or an address no Entry holds answers 404. The address of an Entry [deleted for good](content-types.md#deleting-for-good) answers 301 to its replacement's current URL, or 410 Gone.

### Changing an address

When a slug or a Prefix changes, the former address keeps answering, as a 301 to the Entry's current URL, once the Translation has been public: its publication date has passed, even if it was unpublished since. A Redirect always leads to the final address in one step, so a bookmark, a search result or a browser's cached Redirect never ends on a 404. A Translation never public moves without leaving anything behind.

A Redirect lasts until another Entry takes its address, which replaces it. A Draft given a past publication date by hand counts as public too: its former address answers 404 until the Entry is visible, then redirects.

### Reserved paths

Kami answers some addresses itself, so no Path may take them, in any Locale: `media`, `sitemaps` and `kami` as first segment, and `sitemap.xml` and `robots.txt` whole. In the default Locale, no Path may start with another Locale's code either, as `/en/…` belongs to English. Saving such a slug fails on the slug field, and a type whose Prefix takes one, a [Singleton](content-types.md#singletons)'s Prefix as a whole Path included, fails at boot.

Adding a Locale later can hide a Path saved before: a page at `/de/about` stops answering once `de` is a Locale. `kami:doctor` lists those Paths; change their slug.

## After a deploy

Saving an Entry reserves its Path, a Draft's included. A change in code moves Paths only when `kami:install` runs, as every deploy does ([Deployment](deployment.md#later-deploys)):

- a new Prefix moves the type's Entries to their new addresses; the former ones redirect there [once public](#changing-an-address);
- a new Locale gives each Entry of an untranslatable type its Path there;
- a Path whose Translation is gone is deleted.

`kami:install` prints what it did, as `Paths: 0 created, 12 rewritten, 0 deleted.`, and changes nothing when nothing changed. It reads the Entries in batches, so its memory stays flat whatever their number. It runs from the command line only, never in a request.

When a new address is already another Entry's, `kami:install` names both and fails, leaving that Path where it was: change one of the two slugs in the admin, then run it again. Until the next `kami:install`, `kami:doctor` warns that Paths do not follow the declarations.

Kami leaves the Paths of a Locale or a type you removed from `config/kami.php` as they are, with their Entries; `kami:doctor` lists them ([Multilingual](multilingual.md#locales)), until a [migration deletes them](content-types.md#schema-changes).

## Rendering

The Entry is rendered by its Content Type's `render()`. By default, it returns the view named after the type's handle, `kami.{handle}`, with the Entry as `$entry`. For an `Article`, that is `resources/views/kami/article.blade.php`:

```blade
<article>
    <h1>{{ $entry->title }}</h1>
    <p>{{ $entry->summary }}</p>
</article>
```

The views are the site's: Kami ships no page view, only the `x-kami::` components below. Print values with `{{ }}`, which escapes them: a title or a Field holding markup shows as text.

### Overriding `render()`

A page that needs more data, or another kind of response, overrides `render()` on its type. Kami calls it through the container, so it may ask for more dependencies; PHP requires each extra parameter of an override to have a default:

```php
use App\Services\EventCalendar;
use Illuminate\Contracts\View\View;
use Psyao\Kami\Content\Entry;
use Symfony\Component\HttpFoundation\Response;

public function render(Entry $entry, ?EventCalendar $calendar = null): View | Response
{
    return view('kami.article', [
        'entry' => $entry,
        'events' => $calendar?->upcoming(),
    ]);
}
```

It may return any view or response: a redirect, JSON, or a controller's response called from there.

### Images

`<x-kami::image :media="$entry->cover" preset="card" />` renders a Media item as a `<picture>` with its formats, a `srcset`, its dimensions, lazy loading and its alt text ([Media](media.md#showing-an-image)). It renders nothing for a null Media item, so an empty optional Field needs no `@if`.

### Dates

`<x-kami::date :value="$entry->published_at" />` renders a `<time class="kami-date">` whose `datetime` is the moment in UTC (ISO 8601), shown in the Site's `timezone` and the page's language. `format` takes a Carbon `isoFormat()` pattern, `LL` by default: "1 mars 2026", "March 1, 2026"; `format="LLL"` adds the time. A Date Field holds a day, not a moment: pass `day` so no time zone shifts it, and `datetime` holds the day alone:

```blade
<x-kami::date :value="$entry->event_day" day />
```

It renders nothing for a null value.

### Head

`<x-kami::seo />` prints the page's title, description, canonical URL and robots tag, in place of the layout's `<title>` ([SEO](seo.md#the-head)).

### Language switcher

`<x-kami::locales />` links the page's Entry in its other Locales ([Multilingual](multilingual.md#language-switcher)).

### Overriding a component

Each `x-kami::` component is an anonymous Blade view. Its name and props are public API; its markup is yours to change. A file at `resources/views/vendor/kami/components/locales.blade.php` replaces `<x-kami::locales />`: copy the package's file there and edit the copy. `php artisan vendor:publish --tag=kami-views` copies all of Kami's views at once, which then no longer follow its updates.

### The Preview banner

The admin's Preview calls the same `render()` with an Entry that was never saved ([Publishing](publishing.md#preview)), and a [Preview link](publishing.md#preview-links) with a Draft or Scheduled one: `render()` must not assume its Entry is stored or visible. Put the banner at the top of your layout's `<body>`:

```blade
<body>
    <x-kami::preview-banner />
    ...
</body>
```

It renders nothing on a public page. In a Preview it renders an `<aside class="kami-preview-banner">` landmark saying the page holds unsaved changes visitors do not see, in the Preview's Locale; through a Preview link, that visitors may not see this saved version, and when the link expires, in the Site's time zone. It carries no CSS: style `.kami-preview-banner` as you wish. Kami does not change your HTML, so a layout without the component shows no banner.

## Error pages

Kami ships no error views: Laravel renders `resources/views/errors/{code}.blade.php`, yours, as for any application. The starter kit ships them. To render them in the visitor's Locale, call `Kami::exceptions()` in `bootstrap/app.php`, before your own callbacks:

```php
use Illuminate\Foundation\Configuration\Exceptions;
use Psyao\Kami\Facades\Kami;

->withExceptions(function (Exceptions $exceptions): void {
    Kami::exceptions($exceptions);
})
```

The Locale then comes from the URL's first segment, as on Kami's pages: `/en/unknown` answers its 404 in English, on Kami's route, on your own routes and when no route answers. `__()`, `app()->getLocale()` and Kami's components use it. Kami renders nothing itself; a callback of yours registered before Kami's and returning a response skips it. A request whose first segment names no Locale gets the default Locale, on your admin's error pages and your own routes too, whatever locale your code set earlier in the request. An error raised while the application boots, such as a broken config file, renders in the application's locale. It behaves the same on shared hosting and on a server.

Write the views in two kinds:

- **4xx** (403, 404, 410, 419, 429) can use your layout, with `<x-kami::seo :title="__('Not Found')" />` for a head of `{title} – {Site}` with no canonical URL ([SEO](seo.md#the-head)). `<x-kami::locales />` renders nothing there, as there is no Entry.
- **5xx** (500, 503) stand alone: no database query, no `@vite`, no script, a `<meta name="robots" content="noindex">`, and a stylesheet served as a file from `public/`. A 500 may mean the database or the build is the problem, and an inline `<style>` breaks the Content Security Policy.

An error raised on a route keeps that route's security headers: Kami's route sends its own, and your routes theirs once `SecurityHeaders` is in the `web` group ([Security](security.md#your-own-pages)). Maintenance mode's 503 answers before any route, so it carries none: keep that view static, with no visitor input in it. `php artisan down` answers it before any cached page ([Page cache](page-cache.md#serving-before-laravel-boots)). With `--render`, the command renders the view once, outside any request, so in no visitor's Locale.

## Query count

An Entry page costs four queries before your view runs: its Path, the Entry, its Translations and its Field values, in every Locale, so Fallback adds none. Kami's tests assert that number, so it does not grow unnoticed.
