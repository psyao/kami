# SEO

Each page gets a head from `<x-kami::seo />`: its title, description, canonical URL, language links, robots tag and social card, derived from what editors wrote with nothing to configure. One switch decides whether search engines are welcome on a Site. It drives a `robots.txt` that `kami:install` writes, and an `X-Robots-Tag` header on every front response. A static sitemap lists every page with its language links, rebuilt when it is stale (ADR 0027).

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
<link rel="alternate" hreflang="fr" href="https://example.com/articles/bonjour">
<link rel="alternate" hreflang="en" href="https://example.com/en/articles/hello">
<link rel="alternate" hreflang="x-default" href="https://example.com/articles/bonjour">
<meta property="og:title" content="Hello – Kami">
<meta property="og:description" content="The start of the article…">
<meta property="og:site_name" content="Kami">
<meta property="og:type" content="article">
<meta property="og:locale" content="en_GB">
<meta property="og:locale:alternate" content="fr_CH">
<meta property="article:published_time" content="2026-11-08T09:00:00+00:00">
<meta property="article:modified_time" content="2026-11-08T10:30:00+00:00">
<meta property="og:image" content="https://example.com/media/01k…/3f9a1c2b/kami-social-1200.jpg">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="The lake at dawn">
<meta name="twitter:card" content="summary_large_image">
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

### Languages

On a Site with several Locales, a page links to its Translations with `hreflang`, its own included, in the order of the Site's Locales:

- only the Translations visible to visitors and not Hidden from search engines, so a hidden page neither links nor is linked to;
- `x-default` to the default Locale's Translation, when it is one of them;
- nothing below two links, so a page in a single Locale, and every page of a Site with one Locale, has none;
- an untranslatable Entry links its Path in each Locale to the others.

`hreflang` is the Locale's code as declared, which is also its URL segment. A Preview has no language links.

`og:locale` needs a region, which no language code alone gives. Declare it on the Locale ([Multilingual](multilingual.md#locales)):

```php
'locales' => ['fr' => ['region' => 'CH'], 'en' => ['region' => 'GB']],
```

This gives `og:locale` `fr_CH` on French pages and `en_GB` on English ones. A region in the code, `fr-ch`, gives the same, with `fr-ch` as hreflang and URL segment. Without a region, a Locale's pages have no `og:locale`. `og:locale:alternate` lists the other Locales of the page's language links that have a region.

### Robots

- `noindex, nofollow` while the Site is not [indexable](#indexable-switch);
- `noindex` for a Translation Hidden from search engines (`noindex`);
- `noindex` in a Preview, always;
- no tag otherwise.

### Social card

`og:title`, `og:description` and `og:site_name` repeat the title, the description and the Site name. `og:type` is the type's `$ogType`, `website` by default:

```php
final class Article extends ContentType
{
    protected string $ogType = 'article';
}
```

An `article` adds `article:published_time`, the Translation's publication date, and `article:modified_time`, the Entry's last save in any Locale. `twitter:card` is `summary_large_image` with an image and `summary` without; X reads the rest from Open Graph, so no other `twitter:*` tag is printed.

### Social image

The page's image is the first of:

1. the Entry's own Social image, `seo_image`, shared by all its Translations;
2. its type's `socialImage()`, `null` by default;
3. the Site settings' `default_social_image`.

With none, no `og:image`. An image with no Conversion, an SVG, is skipped for the next one, as link previews read JPEG and PNG only. Override `socialImage()` to use an image Field the type already has:

```php
public function socialImage(Entry $entry): ?Media
{
    $cover = $entry->getAttribute('cover');

    return $cover instanceof Media ? $cover : null;
}
```

`og:image` is the image's `kami-social` Conversion: cut at 40:21 around the centre, 1200 × 630, in JPEG for a JPEG and in PNG for anything else, never AVIF or WebP ([Image Presets](media.md#image-presets)). A smaller image is never upscaled: `og:image:width` and `og:image:height` give the size of the file served. `og:image:alt` is the image's alt text in the page's Locale, else the default Locale's; a decorative image, or one with no alt text, has none.

`seo_image` is written through `SaveEntry`, as a Media item's id, on a type with a URL, and refused on any other. It is a Reference, so the Media item it names cannot be deleted while the Entry uses it, as for a Media Field. The handle `seo_image` is reserved: no Field may take it.

```php
resolve(SaveEntry::class)->handle(Article::class, ['seo_image' => $media->id], $entry);
```

### Writing the values

`meta_title` (255 characters at most), `meta_description` and `noindex` are Translation attributes, one value per Locale. `SaveEntry` writes them on a type with a URL and refuses them on any other, Global Sets included:

```php
resolve(SaveEntry::class)->handle(Article::class, [
    'meta_title' => 'A title for search results',
    'meta_description' => 'What the article says, in a sentence.',
    'noindex' => false,
], $entry, 'en');
```

They read as `$entry->meta_title`, `$entry->meta_description`, `$entry->noindex` and `$entry->seo_image`. The Site's `default_description` and `default_social_image`, an images-only Media Field shared by all Locales, are Fields of `SiteSettings`, written through `SaveEntry` as its `site_name`.

### Pages Kami does not render

On your own routes and on error pages, pass the title and the description:

```blade
<x-kami::seo :title="__('Contact')" :description="__('Write to us.')" />
```

The title becomes `Contact – Kami` through the same `kami::seo.title` string; without one, the Site name alone. There is no canonical URL and no language link; `og:type` is `website`, `og:locale` that of the page's Locale, and the image is the Site's default Social image. A page Kami renders ignores both props.

### Reading the values

`Kami::seo()` returns the request's `Psyao\Kami\Seo\PageMeta`: `title`, `description`, `canonical`, `robots`, `siteName`, `image`, `imageWidth`, `imageHeight`, `imageAlt`, `ogType`, `publishedTime`, `modifiedTime` and `ogLocale`, each `null` when it has no value but `title`, `siteName` and `ogType`; `alternates`, the language links' URLs by Locale code then `x-default`, and `ogLocaleAlternates`, empty without any. Kami resolves it before it calls your type's `render()`, so an override can pass it on, as an Inertia page does in its props. Outside a page Kami renders, it holds the Site's values: the Site name as title, and the robots value of the switch.

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

## Sitemap

`/sitemap.xml` is a sitemap index over one file per Locale, `/sitemaps/{locale}-{n}.xml`, each holding at most 10,000 URLs. Both are static files in `public/`, which Apache or nginx serve without PHP. Each URL has its absolute address, its `lastmod`, the date its Translation was last saved, and the same language links as the page's head, `x-default` included:

```xml
<url>
  <loc>https://example.com/articles/bonjour</loc>
  <lastmod>2026-11-08T10:00:00+00:00</lastmod>
  <xhtml:link rel="alternate" hreflang="fr" href="https://example.com/articles/bonjour"/>
  <xhtml:link rel="alternate" hreflang="en" href="https://example.com/en/articles/hello"/>
  <xhtml:link rel="alternate" hreflang="x-default" href="https://example.com/articles/bonjour"/>
</url>
```

It lists the visible Translations that have a URL and are not [Hidden from search engines](#robots). The home page appears once per Locale, at `/` and `/en`. Global Sets, Entries of a type without URLs, Drafts, Scheduled Entries before their time and the Trash are left out. There is no `priority` and no `changefreq`.

### When it is rebuilt

Every save, publication, Trash, restore or deletion marks the sitemap stale, and so does a Scheduled Entry once its time has come. `php artisan kami:sitemap` rebuilds it when it is stale and does nothing otherwise. It writes the files into a temporary directory, then swaps it in, so a crawler never reads half a sitemap.

- On a server, Kami registers `kami:sitemap` `hourly()` in Laravel's scheduler: run `schedule:run` from cron, as Laravel's documentation shows.
- On shared hosting, with no scheduler, `kami:install` rebuilds a stale sitemap on each deploy, until Kami gets a scheduler of its own that runs on visits.

When the files are missing, on a fresh install for instance, the first request for `/sitemap.xml` or for a Locale's file reaches Laravel through the usual "file not found" rewrite. Kami builds the files in that request, then serves them; later requests get the static files. The page cache never stores them. `kami:doctor` warns about a sitemap stale for more than two hours, while the Site is indexable.

## Host routes

Apache serves a file in `public/` before Laravel sees the request, so a file would shadow a route of your application at the same address. When one of your routes answers `/robots.txt`, `kami:install` writes no `robots.txt`; when one answers `/sitemap.xml`, Kami writes no sitemap index. `kami:doctor` warns about both (ADR 0038). Make sure your `robots.txt` disallows the Kami panel and names the sitemap.

Kami still writes its per-Locale files. A sitemap index cannot list another index, so list them in yours: `Kami::sitemapUrls()` returns their absolute URLs, building them first when they are missing.

```php
use Psyao\Kami\Facades\Kami;

foreach (Kami::sitemapUrls() as $url) {
    // <sitemap><loc>{$url}</loc></sitemap>
}
```

## Shared hosting and a server

| Subject | Shared hosting | Server |
| --- | --- | --- |
| **The head** | Computed in the request from the page's Entry, its Social image and the Site settings, a constant number of queries; stored with the page in the [page cache](page-cache.md) | The same |
| **`robots.txt`** | A static file `kami:install` writes over SSH; the host never answers its own | The same; Apache or nginx serve it |
| **`X-Robots-Tag`** | Set in the request, and replayed with cached pages; on Conversions Apache serves, from the `.htaccess` `kami:install` writes | The same; on nginx, in its `location` blocks for `/media/` |
| **Sitemap** | Static files Apache serves; rebuilt by `kami:install` on a deploy when stale, until Kami's own scheduler; built in the request only when missing | `kami:sitemap` hourly from Laravel's scheduler in cron; nginx's `try_files` reaches Kami when a file is missing |
