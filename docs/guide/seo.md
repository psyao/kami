# SEO

Each page gets a head from `<x-kami::seo />`: its title, description, canonical URL and robots tag, derived from what editors wrote with nothing to configure. One switch decides whether search engines are welcome on a Site. It drives a `robots.txt` that `kami:install` writes, and an `X-Robots-Tag` header on every front response (ADR 0027).

## The head

Put `<x-kami::seo />` in your layout's `<head>`, in place of its `<title>`:

```blade
<head>
    <meta charset="utf-8">
    <x-kami::seo />
</head>
```

On a page Kami renders, it prints:

```html
<title>Hello – Kami</title>
<meta name="description" content="The start of the article…">
<link rel="canonical" href="https://example.com/en/articles/hello">
<meta property="og:url" content="https://example.com/en/articles/hello">
```

Every value is escaped, and a tag with no value is left out.

### Title

The Translation's Meta title, else the page's title and the Site name through the translation string `kami::seo.title`, `:title – :site`. Change the separator in `lang/vendor/kami/{locale}/seo.php`. The page at the Site's root Path, a home Singleton for instance, shows the Site name alone.

The Site name is `site_name` in the Site settings, in the page's Locale. Left blank, it reads the default Locale's, then the application's name (`APP_NAME`).

### Description

The Translation's Meta description, else the type's `summary()`, else the Site settings' default description on the page at the root Path only. With none of them, no tag.

`summary()` defaults to the first 160 characters of the type's first RichText Field, cut at a word. Override it on the type to read another Field. `$entry->summary()` calls it; `$entry->summary`, with no call, still reads a Field you named `summary`:

```php
public function summary(Entry $entry): ?string
{
    return $entry->fallbackLocale('summary') === null ? $entry->summary : null;
}
```

None of these values falls back to another Locale: a page described in another language would be described wrongly. A Field shown through the Fallback on the page is left out of its description, so check `fallbackLocale()` as above when you read one yourself.

`kami:doctor` warns about a type with a URL, the default `summary()` and no RichText Field: its pages have no description unless editors write one. Override `summary()`, returning `null` if the type has nothing to say.

### Canonical URL

The page's absolute URL, built from the Site's declared `url`, its Locale segment and its Path. It never uses the request's host, so a forged `Host` header cannot change it, and it drops the query string, `utm_*` parameters included. `og:url` takes the same value.

### Robots

- `noindex, nofollow` while the Site is not [indexable](#indexable-switch);
- `noindex` for a Translation Hidden from search engines (`noindex`);
- `noindex` in a Preview, always;
- no tag otherwise.

### Writing the values

`meta_title` (255 characters at most), `meta_description` and `noindex` are Translation attributes, one value per Locale. `SaveEntry` writes them on a type with a URL and refuses them on any other, Global Sets included:

```php
resolve(SaveEntry::class)->handle(Article::class, [
    'meta_title' => 'A title for search results',
    'meta_description' => 'What the article says, in a sentence.',
    'noindex' => false,
], $entry, 'en');
```

They read as `$entry->meta_title`, `$entry->meta_description` and `$entry->noindex`. The Site's `default_description` is a Field of `SiteSettings`, written through `SaveEntry` as its `site_name`.

### Pages Kami does not render

On your own routes and on error pages, pass the title and the description:

```blade
<x-kami::seo :title="__('Contact')" :description="__('Write to us.')" />
```

The title becomes `Contact – Kami` through the same `kami::seo.title` string; without one, the Site name alone. There is no canonical URL. A page Kami renders ignores both props.

### Reading the values

`Kami::seo()` returns the request's `Psyao\Kami\Seo\PageMeta`: `title`, `description`, `canonical`, `robots` and `siteName`, each `null` when it has no value but `title` and `siteName`. Kami resolves it before it calls your type's `render()`, so an override can pass it on, as an Inertia page does in its props. Outside a page Kami renders, it holds the Site's values: the Site name as title, and the robots value of the switch.

In a Preview, the head is that of the unsaved form, with the saved URL as canonical: none while the saved Translation is not visible, a Draft for instance.

## Indexable switch

`KAMI_INDEXABLE` in `.env` sets `kami.indexable`. Left unset, it is on in production (`APP_ENV=production`) and off everywhere else, so a staging copy stays out of search engines with nothing to configure:

```dotenv
# A production site before its launch
KAMI_INDEXABLE=false
```

When the Site is not indexable:

- every response of Kami's front routes carries `X-Robots-Tag: noindex, nofollow`: pages, 404s, Conversions and Preview links. Conversions Apache serves from `public/media` get it from the `.htaccess` `kami:install` writes; on nginx, add it yourself ([Hosting](hosting.md#nginx));
- `robots.txt` disallows everything.

The admin panel, the admin Preview and Preview links always send `X-Robots-Tag: noindex, nofollow`, whatever the switch says. Your own routes are yours: Kami adds nothing to them.

After you change the switch, run `kami:install`, as every deploy does. It rewrites `robots.txt`, and it makes cached pages stale, as they keep the header they were stored with ([Page cache](page-cache.md#when-pages-go-stale)).

`kami:doctor` warns about a production install that is not indexable, and about one outside production that is.

## robots.txt

`kami:install` writes `public/robots.txt` on every deploy, as a static file Apache or nginx serve without PHP. On Infomaniak shared hosting, a missing `robots.txt` is answered by the host itself, with no rule for the admin, so the file must exist.

When the Site is indexable, the file disallows each Kami panel and names the sitemap, as an absolute URL built from the Site's `url`:

```text
# Managed by Kami (kami:install). Delete this line to own this file.
User-agent: *
Disallow: /admin

Sitemap: https://example.com/sitemap.xml
```

A panel at the root, or on a domain other than the Site's, gets no `Disallow` line: it would hide the front, or another host serves it.

When the Site is not indexable:

```text
# Managed by Kami (kami:install). Delete this line to own this file.
User-agent: *
Disallow: /
```

`Disallow: /` keeps crawlers from reading the `noindex` header, so a page already indexed stays indexed. The switch is meant for copies that were never indexed: staging, and a site before its launch.

### Owning the file

The first line is Kami's marker. `kami:install` rewrites the file while it starts with that line. Delete the line and the file is yours: Kami never touches it again. `kami:doctor` then warns when your file names no sitemap, while the Site is indexable. It also warns when the managed file differs from what `kami:install` would write, such as after a change to the switch or the panel path without a deploy.

## Host routes

Apache serves a file in `public/` before Laravel sees the request, so a file would shadow a route of your application at the same address. When one of your routes answers `/robots.txt`, `kami:install` writes no `robots.txt`, and `kami:doctor` warns about it, and about a route answering `/sitemap.xml` too (ADR 0038). Make sure your `robots.txt` disallows the Kami panel and names the sitemap, and that your sitemap lists the Site's pages.

## Shared hosting and a server

| Subject | Shared hosting | Server |
| --- | --- | --- |
| **The head** | Computed in the request from the page's Entry and the Site settings, a constant number of queries; stored with the page in the [page cache](page-cache.md) | The same |
| **`robots.txt`** | A static file `kami:install` writes over SSH; the host never answers its own | The same; Apache or nginx serve it |
| **`X-Robots-Tag`** | Set in the request, and replayed with cached pages; on Conversions Apache serves, from the `.htaccess` `kami:install` writes | The same; on nginx, in its `location` blocks for `/media/` |
