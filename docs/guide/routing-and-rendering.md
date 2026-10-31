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

An Entry answers only while it is visible. A Draft, an Entry whose publication date is still to come, or an address no Entry holds answers 404.

## After a deploy

Saving an Entry reserves its Path, a Draft's included. A change in code moves Paths only when `kami:install` runs, as every deploy does ([Deployment](deployment.md#later-deploys)):

- a new Prefix moves the type's Entries to their new addresses; Kami does not redirect the former ones yet, so they answer 404;
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

The views are the site's: Kami ships none. Print values with `{{ }}`, which escapes them: a title or a Field holding markup shows as text.

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

## Query count

An Entry page costs four queries before your view runs: its Path, the Entry, its Translations and its Field values, in every Locale, so Fallback adds none. Kami's tests assert that number, so it does not grow unnoticed.
