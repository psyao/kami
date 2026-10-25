# Reference site: psyao.ch on Velox

Research note for Kami. Facts only, read from `~/Code/psyao` (git HEAD `9e9e7ff`, "feat: describe each post by the start of its body"). Every citation is `path:line` relative to `~/Code/psyao`. `VELOX-MIGRATION.md` is the site's own friction log (steps and frictions numbered there). `README.md` is Laravel's stock README and says nothing about the site (`README.md:10-22`).

## 1. What the site is

- A monolingual French technical and music blog, formerly WordPress, migrated **locally** onto Velox; it is not in production on Velox. Served by Herd at `https://psyao.test`, MariaDB 10.11 database `psyao_dev`, the former site read from database `wp_psyao` (`VELOX-MIGRATION.md:9`).
- The live site (checked read-only on 2026-10-25) still runs WordPress: `https://psyao.ch/` answers 301 to `https://www.psyao.ch/` behind Cloudflare, and the home page's `generator` meta says "WordPress 6.9.1" and "Site Kit by Google 1.173.0". Its links confirm the patterns below: `/2011/05/06/amnesys-lose-yourself/`, `/bloc-note/symfony/installation/`, `/blog/`, `/page/2/`, `/feed/`, `/comments/feed/`, `/contact/`, `/portfolio/`, plus `/wp-json/` and `/xmlrpc.php?rsd`.
- Monolingual: `'locales' => []` (`config/velox.php:32`); the language switcher partial renders only with more than one Locale (`resources/views/velox/partials/locales.blade.php:1`).
- Volumes after import (enforced by the import's count check, `app/Console/Commands/ImportCommand.php:356-369`): 146 Posts, 10 Pages (3 of them Drafts), the Home Page « Accueil », 8 Categories, 306 Tags, 2 `primary` Menu Items, 1 Media Item, 158 Redirects, 99 Video Blocks. 156 Entries in total (`tests/Feature/ImportTest.php:398`).

## 2. Versions

| Package | Constraint | Locked |
| --- | --- | --- |
| PHP | `^8.3` (`composer.json:12`) | |
| laravel/framework | `^13.17` (`composer.json:13`) | v13.38.0 (`composer.lock`) |
| psyao/velox | `^1.1` (`composer.json:15`), VCS repo `https://github.com/psyao/velox` (`composer.json:92-98`) | v1.1.0 |
| filament/filament | via Velox | v5.10.3 |
| livewire/livewire | via Velox | v4.4.8 |
| ueberdosis/tiptap-php | via Velox | 2.2.1 |
| spatie/shiki-php | via Velox | 2.4.0 |
| intervention/image | via Velox | 4.4.0 |
| laravel/tinker | `^3.0` (`composer.json:14`) | v3.0.2 |
| pestphp/pest | `^5` (`composer.json:24`) | v5.3.3 |
| phpunit/phpunit | `^13` (`composer.json:25`) | 13.4.3 |

Velox history on this site: 0.25 via a symlinked `path` repo (`VELOX-MIGRATION.md:9`), 0.26 (`:12`), 0.27 back to the VCS repo (`:18`), 0.29 (`:19`), 1.1 (`:20`). The private repo's token comes from Composer's local auth since 1.1 (`VELOX-MIGRATION.md:20`).

Runtime config relevant to content: `app.timezone` is `UTC` (`config/app.php:68`); the `mysql` and `mariadb` connections force `'timezone' => '+00:00'` (`config/database.php:50`, `config/database.php:72`); `display_timezone` is `Europe/Zurich` (`config/velox.php:39`). The Filament panel is `admin` at `/admin` with `VeloxPlugin` (`app/Providers/Filament/AdminPanelProvider.php:26-32`).

## 3. Content model

### Content Types (registered in `config/velox.php:12-15`)

**Post** (`app/ContentTypes/Post.php`)
- Labels « Article » / « Articles » (`app/ContentTypes/Post.php:13-21`).
- URL prefix `articles` (`:23-26`); not hierarchical (`:28-31`); not orderable (`:33-36`); has an Archive (`:38-41`); has an RSS feed (`:43-46`).
- SEO description falls back to the `body` field (`:48-51`): a post with no Yoast description is described by the first 160 characters of its body as plain text, in its `<head>` and its feed item (`VELOX-MIGRATION.md:20`).
- Taxonomies: Category, Tag (`:53-56`).
- Fields: one `RichText::make('body')->blocks([Video::class])->code()` (`:58-62`). No excerpt, no featured image, no author field.

**Page** (`app/ContentTypes/Page.php`)
- No URL prefix (`app/ContentTypes/Page.php:14-17`), hierarchical (`:19-22`), orderable (`:24-27`), no Archive (`:29-32`).
- Fields: one `RichText::make('body')->code()` (`:56-61`), no Blocks.
- `render()` override for the Home Page (`:35-54`): see § 5 and § 6.

Both types have only Velox's built-in Entry columns besides `body`: title, slug, status, `published_at`, `parent_id`, `created_by`, `seo`, `created_at`, `updated_at` (as the importer fills them, `app/Console/Commands/ImportCommand.php:164-175`).

### Taxonomies

- **Category** (`app/Taxonomies/Category.php`): prefix `category` (`:9-12`), hierarchical (`:14-17`), not single (`:20-23`), not required (`:26-29`), public with a Term Archive (`:32-35`).
- **Tag** (`app/Taxonomies/Tag.php`): prefix `tag` (`:9-12`), flat (`:14-17`), not single, not required, public (`:20-35`).
- Although Category is declared hierarchical, all 8 categories are imported **flat**, with WordPress's slugs, keyed by `term_taxonomy_id` (`app/Console/Commands/ImportCommand.php:85-114`). Names are HTML-entity-decoded (`:101`, `:384-387`), e.g. « TV & Movies », « ID&T » (`tests/Feature/ImportTest.php:148-149`).
- A post's Terms are attached in WordPress's display order, by name, because every `term_order` is 0 (`app/Console/Commands/ImportCommand.php:133-142`; `VELOX-MIGRATION.md:11`). Example: a post in categories `blog`, `development`, `web` (`tests/Feature/ImportTest.php:147`).
- Yoast stored no SEO data for Terms (`VELOX-MIGRATION.md:20`).

### Authors

- No author model beyond Velox's Accounts. Every Entry is `created_by` the single Developer Account (`app/Console/Commands/ImportCommand.php:69`, `:170`; `tests/Feature/ImportTest.php:96`, `:162`), seeded from `DEVELOPER_*` env by `DeveloperSeeder` (`database/seeders/DeveloperSeeder.php:13-15`; `config/psyao.php:6-10`).
- No view prints an author (`resources/views/velox/post.blade.php:1-18`). WordPress author pages (`/author/<nicename>/`, `/?author=`) answer 404 (`app/ContentTypes/Page.php:46-48`; `tests/Feature/ImportTest.php:277`).

### Pages

- 9 WordPress pages imported (published, pending and draft), « Blog » (empty) and « Page d'exemple » (WordPress's sample) skipped (`app/Console/Commands/ImportCommand.php:37-38`, `:146-157`), plus the new « Accueil » (`:194-209`) = 10.
- Published tree, in WordPress order (`menu_order` then title), « Accueil » last: Bloc-note > Symfony > {Installation, Génération d'un bundle}; Contact; Portfolio; Accueil (`tests/Feature/ImportTest.php:71-76`).
- Drafts: « À propos », « Réalisations », « Routage » (`tests/Feature/ImportTest.php:57`); they answer 404 (`:103-105`). Pending and draft WordPress pages become Drafts with no `published_at` (`app/Console/Commands/ImportCommand.php:162-168`).
- Ordering is kept by creating siblings in order, relying on Velox's append-on-create for orderable types (`app/Console/Commands/ImportCommand.php:154`).

### Dates

- `published_at` and `created_at` from `post_date_gmt`, `updated_at` from `post_modified_gmt`; a Draft never given a GMT date (`0000-00-00 00:00:00`) is dated from its local date in `Europe/Zurich` converted to UTC (`app/Console/Commands/ImportCommand.php:163-175`, `:378-382`).
- Views print `published_at->inDisplayTimezone()->isoFormat('LL')` and keep `datetime=""` in UTC (`resources/views/velox/post.blade.php:7`; `VELOX-MIGRATION.md:19`); tested by `tests/Feature/DisplayTimeZoneTest.php:12-29` (22:30 UTC on 1 July reads as 2 July).

## 4. Rich text and blocks

- Bodies are Velox RichText (TipTap JSON documents), produced by `$type->field('body')->fromHtml($html)` (`app/Console/Commands/ImportCommand.php:249`).
- **Inline code and `<pre>`**: `->code()` on both types (`app/ContentTypes/Post.php:61`, `app/ContentTypes/Page.php:59`) yields `code` marks and `codeBlock` nodes, rendered `<code>` and `<pre><code>` (`tests/Feature/ImportTest.php:313-327`). Only Bloc-note pages hold `<code>` (10) and `<pre>` (1) (`tests/Feature/ImportTest.php:319`; `VELOX-MIGRATION.md:26`). Velox renders them without language or highlighting (`vendor/psyao/velox/resources/boost/skills/velox-development/SKILL.md:27`).
- **Headings**: `h4` is mapped to `h3` before conversion (`app/Console/Commands/ImportCommand.php:248`).
- **Video Block** (`Velox\Cms\Blocks\Video`, allowed on Post only, `app/ContentTypes/Post.php:61`): stored as a custom block with the watch URL; renders `<iframe class="velox-video">` with `youtube-nocookie.com/embed/<id>` or `player.vimeo.com/video/<id>` (`tests/Feature/ImportTest.php:334`, `:344-345`; URL mapping in `vendor/psyao/velox/src/Fields/VideoUrl.php:19-20`). 99 Blocks for 98 playable videos (one, `6BoJY9mor1I`, in posts 303 and 310) (`VELOX-MIGRATION.md:31`).
- **Dead videos**: a committed list `DEAD_VIDEOS` of 38 YouTube IDs (34 gone/404, 4 private or not embeddable/403), checked once by oEmbed on 2026-10-21, so the import makes no network call (`app/Console/Commands/ImportCommand.php:40-53`). A dead video produces nothing (`:265`); a paragraph next to it stays (`tests/Feature/ImportTest.php:352-363`). 10 bodies that held only a video (pre-Block step) became `null` (`VELOX-MIGRATION.md:12`).
- **Images**: only one body image survives, post 60's SpriteMe screenshot, stored in the repo at `app/WordPress/spriteme.png` (downloaded once from Blogger), uploaded as a Media Item and referenced as `<img data-id>` (`app/Console/Commands/ImportCommand.php:55-56`, `:220-230`, `:244-246`). It renders with its Media URL and alt « SpriteMe », 400×158, at the library root (`tests/Feature/ImportTest.php:368-375`). All other images are gone (see § 8 losses).
- **Links**: links' `target` and `rel` are dropped by the Post schema (`VELOX-MIGRATION.md:12`). Internal links are rewritten to relative paths (§ 8).
- No other Blocks (no gallery, no embed of other providers), no excerpt (no WordPress post has one, `VELOX-MIGRATION.md:20`).

## 5. Templates and views

All under `resources/views/velox/`, plain unstyled Blade (no CSS framework used in them).

| View | Renders |
| --- | --- |
| `layout.blade.php` | `<x-velox::seo>` in `<head>` (`:6`), Preview banner (`:9-11`), locale switcher (`:12`), `primary` menu in `<nav>` (`:13-15`), `<main>` (`:16-18`), `footer` menu in `<footer><nav>` (`:19-21`) |
| `home.blade.php` | Home Page title and body, then the 10 latest posts (title link + date), unpaged, then « Tous les articles » linking to the Post Archive (`:4-14`). Chosen by `Page::render()` (`app/ContentTypes/Page.php:50-53`) |
| `page.blade.php` | Title + body (`:4-5`) |
| `post.blade.php` | `<article>`: title, date if published (`:6-8`), body (`:9`), list of all Terms of every taxonomy as links (`:10-16`) |
| `archive/post.blade.php` | « Articles » heading, title + date per post, `$entries->links()` pagination (`:4-13`) |
| `taxonomy/category.blade.php` | `$term->breadcrumb()` heading, posts, pagination (`:4-13`) |
| `taxonomy/tag.blade.php` | `$term->name` heading, posts, pagination (`:4-13`) |
| `partials/menu.blade.php` | Recursive `<ul>` with `trail` class, `aria-current="page"`, `target="_blank" rel="noopener"` for new-tab items (`:1-10`) |
| `partials/locales.blade.php` | Language switcher, unused here (`:1-15`) |
| `errors/404.blade.php` | « Not Found » in the layout (`:1-7`) |

- Pagination: 10 per page via `?page=N`, past the last page 404 (Velox's `EntryController::page()`, `vendor/psyao/velox/src/Http/Controllers/EntryController.php:95-107`). The Archive has 15 pages (`tests/Feature/ImportTest.php:178`).
- Feeds have no Blade view in the site; Velox's `FeedController` renders RSS 2.0 (`vendor/psyao/velox/src/Http/Controllers/FeedController.php:15-16`), 20 items (`tests/Feature/ImportTest.php:259`).
- `welcome.blade.php` is Laravel's leftover stub (file list), not routed by the site.

## 6. URL structure

### Public routes answering 200

| Path | Source |
| --- | --- |
| `/` | Home Page « Accueil » (`Entry::setHomePage`, `app/Console/Commands/ImportCommand.php:208`), view `velox.home` (`app/ContentTypes/Page.php:50`) |
| `/articles`, `/articles?page=N` | Post Archive (`app/ContentTypes/Post.php:23-41`; `tests/Feature/ImportTest.php:177-178`) |
| `/articles/<slug>` | Post (`tests/Feature/ImportTest.php:194`) |
| `/<page-slug>[/<child>…]` | Pages, nested by parent: `/bloc-note`, `/bloc-note/symfony`, `/bloc-note/symfony/installation`, `/bloc-note/symfony/generation-bundle`, `/portfolio`, `/contact` (`tests/Feature/ImportTest.php:99-101`) |
| `/category/<slug>[?page=N]` | Category Term Archive (`app/Taxonomies/Category.php:9-12`; `tests/Feature/ImportTest.php:179`) |
| `/tag/<slug>[?page=N]` | Tag Term Archive (`app/Taxonomies/Tag.php:9-12`; `tests/Feature/ImportTest.php:180`) |
| `/feed` | Site-wide RSS, `application/rss+xml; charset=UTF-8` (`tests/Feature/ImportTest.php:256`) |
| `/articles/feed` | Post RSS (`tests/Feature/ImportTest.php:262`) |
| `/sitemap.xml` | Velox sitemap (`vendor/psyao/velox/routes/web.php:38-40`; `tests/Feature/ImportTest.php:181`) |
| `/robots.txt` | Velox (`vendor/psyao/velox/routes/web.php:34-36`) |
| `/admin…`, `/admin/preview/{token}` | Filament + Velox preview (`app/Providers/Filament/AdminPanelProvider.php:27`; `vendor/psyao/velox/routes/web.php:17-21`) |
| `/up` | Laravel health (`bootstrap/app.php:12`) |

Velox's catch-all `{path}` route is a fallback, so site routes win (`vendor/psyao/velox/routes/web.php:23-46`). Velox answers a trailing-slash path with a canonical 301 to the slashless form (`VELOX-MIGRATION.md:16`, friction 13 at `:38`).

### Former WordPress URLs

WordPress permalinks were `/%year%/%monthnum%/%day%/%postname%/`; Yoast stripped the category base (`app/Console/Commands/ImportCommand.php:105-106`, `:124-125`).

Recorded as Velox Redirects by the import (`redirectFrom()`), 158 in total = 146 dated posts + 5 old slugs + 7 categories (`app/Console/Commands/ImportCommand.php:365-367`):
- `/YYYY/MM/DD/<slug>/` → `/articles/<slug>`; the date is `post_date` (WordPress **local** time, not GMT), for the current slug and each non-empty, non-numeric `_wp_old_slug` (`app/Console/Commands/ImportCommand.php:124-131`).
- `/<category-slug>/` → `/category/<slug>` for every category except `blog` (`app/Console/Commands/ImportCommand.php:105-109`).

Site routes in `routes/web.php` (each one 301):
- `/blog` (and `/blog/`) → `/articles` (`routes/web.php:14`). In WordPress `/blog/` was both the « Blog » category and the menu link to an empty « Blog » page; the category keeps only `/category/blog` (`VELOX-MIGRATION.md:37`).
- `/sitemap_index.xml` → `/sitemap.xml` (`routes/web.php:15`).
- `/{name}-sitemap.xml` (Yoast's `post-`, `page-`, `category-`, `post_tag-`, `author-sitemap.xml`) → `/sitemap.xml` (`routes/web.php:16`; `tests/Feature/ImportTest.php:234`).
- `/page/{N}` → `/articles` (N=1) or `/articles?page=N`, `N` matching `[1-9][0-9]*` (`routes/web.php:19`, `:22`).
- `/tag/{tag}/page/{N}` → Tag Archive, same page rule; unknown tag 404 (`routes/web.php:19-20`, `:23`).
- `/{category}/page/{N}` → Category Archive, same rule; unknown category 404 (`routes/web.php:24`).
Laravel matches these with or without the trailing slash (`routes/web.php:9-10`).

Handled by Velox without a record: pages at `/<path>/` and tags at `/tag/<slug>/` reach their URL through the trailing-slash 301 (`VELOX-MIGRATION.md:16`).

Query-string URLs, handled in `Page::render()` for the Home Page (`app/ContentTypes/Page.php:41-48`):
- `/?feed=rss2|rss|rdf|atom` → 301 `/feed`; any other `?feed=` (e.g. `comments-rss2`) → 404.
- `/?p=`, `/?page_id=`, `/?attachment_id=`, `/?m=`, `/?author=` → 404.
- `/?page=2` still serves the home page, unpaged (`tests/Feature/ImportTest.php:111-117`).

`/feed/` → 301 `/feed` via the trailing-slash rule (`tests/Feature/ImportTest.php:251`).

Answer 404 (after a canonical slash 301 where the path ends in `/`): `/comments/feed/`, per-post feeds `/YYYY/MM/DD/<slug>/feed/`, per-term feeds `/music/feed/`, `/tag/idt/feed/`, attachment pages `/YYYY/MM/DD/<post>/<attachment>/`, date archives `/YYYY/`, `/YYYY/MM/`, `/YYYY/MM/DD/`, `/author/<nicename>/` (`tests/Feature/ImportTest.php:265-291`).

The test replays 558 former URLs, each one 301 to a 200: 146 posts + 5 old slugs + 6 pages + 8 categories + 306 tags + 15 pages of `/page/N/` + 14 pages of « Music » + 52 pages of 17 tags + 6 sitemaps (`tests/Feature/ImportTest.php:184-248`, count at `:239`). Former paths are sent through the HTTP kernel directly because Laravel's test client trims trailing slashes (`tests/Feature/ImportTest.php:48-52`; `VELOX-MIGRATION.md:39`).

`public/.htaccess` no longer redirects trailing slashes (Laravel's block removed by `velox:install`), so Apache would answer one hop (`public/.htaccess:1-20`; `VELOX-MIGRATION.md:40`).

## 7. Menus and media

- Menus declared in `config/velox.php:22-25`: `primary` « Main navigation », depth 2; `footer` « Footer », depth 1 (default).
- The import fills `primary` with 2 items: the Post Archive (labelled « Articles » from `pluralLabel()`), then the Bloc-note page (`app/Console/Commands/ImportCommand.php:211-218`). WordPress's menu pointed « Blog » at the empty « Blog » page (`VELOX-MIGRATION.md:13`). The second level exists only as allowed depth; the import adds no child items.
- The import adds **no** `footer` items (no code writes `'menu' => 'footer'`; `app/Console/Commands/ImportCommand.php:211-218`). The layout renders the footer only when it has items (`resources/views/velox/layout.blade.php:19-21`).
- Media: one Media Item uploaded via `MediaLibrary::upload(new UploadedFile($path, $name))` then a second write for the alt (`app/Console/Commands/ImportCommand.php:224-230`; `VELOX-MIGRATION.md:33`). Its files are written outside the DB transaction and remain on disk after a rollback (`app/Console/Commands/ImportCommand.php:222`; `VELOX-MIGRATION.md:36`). Tests fake the `public` and `local` disks (`tests/Feature/ImportTest.php:31-32`). WordPress has exactly one `attachment` post (`tests/Feature/ImportTest.php:269`).

## 8. The WordPress import (`php artisan psyao:import`)

**Source**: the WordPress MariaDB database directly, through a `wordpress` connection (`config/database.php:92-103`: driver `mariadb`, default db `wp_psyao`, prefix `wp_`, `strict => false`, env `WP_DB_*`, `.env.example:30-34`). No WXR/XML is read. Tables read: `term_taxonomy`, `terms`, `term_relationships`, `posts`, `postmeta` (`_wp_old_slug`, `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`), `options` (`blogdescription`) (`app/Console/Commands/ImportCommand.php:95`, `:119`, `:126`, `:135-139`, `:149`, `:188-189`, `:197`).

**Run order** inside one `DB::transaction` (`app/Console/Commands/ImportCommand.php:68-78`): resolve the sole Developer → upload SpriteMe → terms → posts → pages → home → menu → relink bodies → count check.

**Idempotency**: refuses to run if `velox_entries` holds any row; the user must `migrate:fresh --seed` first (`app/Console/Commands/ImportCommand.php:60-64`). A second run returns 1 and changes nothing (`tests/Feature/ImportTest.php:293-298`). The final count check throws and rolls everything back on any mismatch (`app/Console/Commands/ImportCommand.php:353-376`). No upsert, no incremental mode.

**Legacy IDs**: not stored on Entries or Terms. WordPress `term_taxonomy_id` is only an in-memory key during the run (`app/Console/Commands/ImportCommand.php:86-88`, `:99`). Legacy URLs are preserved as Redirects (§ 6). Post IDs appear only in comments and docs.

**Posts**: `post_type = 'post'`, `post_status = 'publish'`, by ID (`app/Console/Commands/ImportCommand.php:119`). Title HTML-decoded; slug = `post_name` (`:165-166`), which Velox passes through `Str::slug()` (`_` → `-`: post 60's `sprite-css_avec-spriteme` becomes `sprite-css-avec-spriteme`, `VELOX-MIGRATION.md:27`; `tests/Feature/ImportTest.php:155-156`).

**SEO**: Yoast `_yoast_wpseo_title` and `_yoast_wpseo_metadesc` copied as written into `seo.title` / `seo.description` (`app/Console/Commands/ImportCommand.php:181-192`); 12 overrides in WordPress (9 titles, 3 descriptions) (`tests/Feature/ImportTest.php:128`; `VELOX-MIGRATION.md:13`). « Accueil » gets `seo.title` « psyao.ch – <tagline> » and the tagline as its body (`app/Console/Commands/ImportCommand.php:197-205`), rendered `<title>psyao.ch – Steve Aguet - Web Developer</title>` (`tests/Feature/ImportTest.php:113-114`).

**Body pipeline** (`app/Console/Commands/ImportCommand.php:237-252`), in order:
1. Remove `<!--more-->` (`:239`).
2. Videos (`:259-283`):
   - an `<ol>` containing `httpv` (post 310's chart) is unwrapped into one paragraph per `<li>` (`:261-263`);
   - a `<p>` opening right before an `<object>` and its `</p>` after are dropped (`:270`; friction 7, `VELOX-MIGRATION.md:32`);
   - Flash `<object>…</object>`: Vimeo `clip_id=(\d+)` → `https://vimeo.com/<id>`, else YouTube `youtube.com/v/<11 chars>` → `https://www.youtube.com/watch?v=<id>` (`:271-279`);
   - `httpv://` / `httpvh://` YouTube URLs (a plugin's syntax) (`:280`);
   - a bare `https?://(www.)?youtube.com/watch?v=…` alone on a line (WordPress oEmbed), tolerant of trailing `\r` (`:282`; `VELOX-MIGRATION.md:31`);
   - each becomes `<div data-type="customBlock" data-id="velox.video" data-config='{"url":"…"}'></div>` unless in `DEAD_VIDEOS`, then nothing (`:265-266`).
3. Shortcodes: every `[caption]…[/caption]` removed whole, caption text included (each held a gone image) (`:240-241`). No other shortcode handling exists in the code.
4. Images: each `<img>`, with an `<a>` wrapping it, removed, except SpriteMe → `<img data-id="<media id>">` (`:242-246`).
5. Empty `<p>` removed (`:247`).
6. `wpautop()` — WordPress's own function and helpers copied verbatim (stated as WordPress 7.1.3) into `app/WordPress/formatting.php:1-4`, `:21`, excluded from Pint (`pint.json:3`). No `wptexturize` (`VELOX-MIGRATION.md:10`).
7. `h4` → `h3` (`app/Console/Commands/ImportCommand.php:248`).
8. `fromHtml()` to the type's TipTap document (`:249`); `null` if empty.
9. Trim `hardBreak` nodes at the start and end of every node's content (wpautop leaves a `<br />` beside moved-out blocks) (`:322-351`).

**URL rewriting** (after all Entries exist) (`app/Console/Commands/ImportCommand.php:285-320`): every link mark whose href matches `https?://(www.)?psyao.ch/(…/)?<last-segment>/?` is pointed at the Entry with slug = last segment (`->sole()`), stored as a relative path (`parse_url(…, PHP_URL_PATH)`), saved with `timestamps = false` to keep WordPress's `updated_at`. Examples: Bloc-note's and Symfony's links → `/bloc-note/symfony/installation`, `/bloc-note/symfony/generation-bundle` (`tests/Feature/ImportTest.php:386-390`). No link to `/wp-content/uploads/` or to `psyao.ch` remains (`tests/Feature/ImportTest.php:377-382`).

**Entity decoding**: titles, term names, tagline via `html_entity_decode(ENT_QUOTES | ENT_HTML5)` (`app/Console/Commands/ImportCommand.php:384-387`).

**Admin round trip**: every one of the 156 Entries is opened and saved through Filament's `EditEntry` Livewire component; body and path must be unchanged (`tests/Feature/ImportTest.php:393-419`).

**Known losses** (all by design in the code or log):
- 38 dead videos dropped (`app/Console/Commands/ImportCommand.php:40-53`).
- All images but one: `[caption]` images and 8 other gone images with their links (`VELOX-MIGRATION.md:15`; `app/Console/Commands/ImportCommand.php:240-246`).
- Link `target`/`rel` attributes (`VELOX-MIGRATION.md:12`).
- `wptexturize` typography (`VELOX-MIGRATION.md:10`).
- `<!--more-->` split (`app/Console/Commands/ImportCommand.php:239`).
- Pages « Blog » and « Page d'exemple » (`app/Console/Commands/ImportCommand.php:37-38`).
- Comments, authors, attachments pages, date archives: not imported, their URLs 404 (§ 6). The importer never reads `wp_comments` or `wp_users` (whole file `app/Console/Commands/ImportCommand.php`).
- Category hierarchy (imported flat, `app/Console/Commands/ImportCommand.php:85-86`).
- Underscores in slugs (`VELOX-MIGRATION.md:27`).

## 9. Other needs

- **SEO**: Velox's `<x-velox::seo>` component in the layout (`resources/views/velox/layout.blade.php:6`); Yoast overrides imported; body-derived descriptions on posts (`app/ContentTypes/Post.php:48-51`). Archive title renders « Articles – psyao.ch » (`tests/Feature/ImportTest.php:177`).
- **Sitemap / robots**: Velox's `/sitemap.xml` and `/robots.txt`; Yoast's sitemap URLs 301 to it (`routes/web.php:15-16`).
- **RSS**: `/feed` and `/articles/feed`, 20 items, each with a description (`tests/Feature/ImportTest.php:250-263`). No comments, per-term or per-post feed (`VELOX-MIGRATION.md:20`).
- **Search**: none. No search route, view or form in the site (`routes/web.php:1-24`, views listed in § 5).
- **Comments**: none in Velox or the site; WordPress comment feeds 404 (`tests/Feature/ImportTest.php:273`).
- **Display time zone**: § 3.

## 10. Workarounds around Velox (site code that exists because Velox lacked something)

| Workaround | Where | Verdict per the log |
| --- | --- | --- |
| Home Page lists latest posts via `Page::render()` returning `velox.home` | `app/ContentTypes/Page.php:34-54` | `render()` is the intended seam; docs gap fixed in 0.27.0 (`VELOX-MIGRATION.md:30`) |
| WordPress query-string URLs (`?feed=`, `?p=`, …) handled inside the Home Page's `render()` | `app/ContentTypes/Page.php:41-48` | Step 8/12 (`VELOX-MIGRATION.md:16`, `:20`) |
| Redirect to an Archive (`/blog`), Yoast sitemaps, paged lists as Site routes, since `redirectFrom()` targets only Entries/Terms and refuses paths a route answers | `routes/web.php:14-24` | Not a Velox gap (`VELOX-MIGRATION.md:37`) |
| Count of Redirects read from the internal `velox_redirects` table (Redirect model `@internal`) | `app/Console/Commands/ImportCommand.php:366-367` | Not worth an API yet (`VELOX-MIGRATION.md:41`) |
| Verbatim `wpautop()` copy | `app/WordPress/formatting.php:1-4` | Chosen over exporting rendered content (`VELOX-MIGRATION.md:10`) |
| Video/caption/image/empty-`<p>` reshaping before `fromHtml()` | `app/Console/Commands/ImportCommand.php:239-247`, `:259-283` | HTML parsing, the import's to reshape (`VELOX-MIGRATION.md:32`, `:35`) |
| Trim hard breaks after `fromHtml()` | `app/Console/Commands/ImportCommand.php:322-351` | Site-side cleanup (`VELOX-MIGRATION.md:12`) |
| Internal links stored as paths, not `$entry->url()` (absolute from `APP_URL`) | `app/Console/Commands/ImportCommand.php:311` | By design, documented 0.27.0 (`VELOX-MIGRATION.md:34`) |
| Media upload from a local file wrapped as `UploadedFile`, alt set by a second write | `app/Console/Commands/ImportCommand.php:226-227` | Docs gap, no API (`VELOX-MIGRATION.md:33`) |
| Media files survive a rolled-back transaction | `app/Console/Commands/ImportCommand.php:222` | Not a Velox gap (`VELOX-MIGRATION.md:36`) |
| `rawGet()` test helper to send exact trailing-slash paths | `tests/Feature/ImportTest.php:48-52` | Laravel's (`VELOX-MIGRATION.md:39`) |
| Gaps since fixed in Velox and removed from the site: `->code()` (0.26), `fromHtml()` (0.26), tiptap-php list-item wrapping (0.26), `.htaccess` slash block (0.27), editor `src` in image nodes (0.27) | — | `VELOX-MIGRATION.md:26`, `:28`, `:29`, `:40`, `:42` |
