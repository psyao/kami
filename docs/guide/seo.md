# SEO

One switch decides whether search engines are welcome on a Site. It drives a `robots.txt` that `kami:install` writes, and an `X-Robots-Tag` header on every front response (ADR 0027).

## Indexable switch

`KAMI_INDEXABLE` in `.env` sets `kami.indexable`. Left unset, it is on in production (`APP_ENV=production`) and off everywhere else, so a staging copy stays out of search engines with nothing to configure:

```dotenv
# A production site before its launch
KAMI_INDEXABLE=false
```

When the Site is not indexable:

- every response of Kami's front routes carries `X-Robots-Tag: noindex, nofollow`: pages, 404s, Conversions and Preview links;
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

Apache serves a file in `public/` before Laravel sees the request, so a file would shadow a route of your application at the same address. When one of your routes answers `/robots.txt`, `kami:install` writes no `robots.txt`, and `kami:doctor` warns about it, and about a route answering `/sitemap.xml` too (ADR 0038). Make sure your `robots.txt` disallows the Kami panel and names the sitemap.

## Shared hosting and a server

| Subject | Shared hosting | Server |
| --- | --- | --- |
| **`robots.txt`** | A static file `kami:install` writes over SSH; the host never answers its own | The same; Apache or nginx serve it |
| **`X-Robots-Tag`** | Set in the request, and replayed with cached pages | The same |
