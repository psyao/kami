# steve-et-aline.ch on Velox: research note

Source: `~/Code/steve-et-aline` (57 commits, last `a25e299` "update Velox to 1.1 and publish the posts' RSS feed"). Every citation is `path:line` relative to that repo. `README.md` is Laravel's stock README (`README.md:1-58`) and says nothing about the site; the real documentation is `VELOX-MIGRATION.md`, `DESIGN.md` and `docs/adr/0001-blade-tailwind-native-js.md`. The live site at https://www.steve-et-aline.ch still runs WordPress (checked read-only with `curl -I`, 25 Oct 2026: the apex host answers 301 to `www.`, and `/photos/nggallery/album/notre-mariage/page/2`, `/photos/?album=1&gallery=4`, `/ngg_tag/lakeparade/` and `/sitemap_index.xml` answer 200). The Velox migration is local only (`VELOX-MIGRATION.md:3`).

## Versions

| Package | Constraint | Locked |
| --- | --- | --- |
| PHP | `^8.3` (`composer.json:12`) | n/a |
| laravel/framework | `^13.17` (`composer.json:14`) | v13.38.0 (`composer.lock:2390`) |
| filament/filament | `^5.10` (`composer.json:13`) | v5.10.3 (`composer.lock:1191`) |
| psyao/velox | `^1.1` (`composer.json:16`), VCS repo `https://github.com/psyao/velox` (`composer.json:95-101`) | v1.1.0, ref `cff5683` (`composer.lock:5064-5069`) |
| livewire/livewire | transitive | v4.4.8 (`composer.lock:3544`) |
| intervention/image | transitive (Velox's variants) | 4.4.0 (`composer.lock:2251`) |
| ueberdosis/tiptap-php | transitive (RichText) | 2.2.1 (`composer.lock:8546`) |
| pestphp/pest / phpunit | `^5` / `^13` (`composer.json:26-27`) | v5.3.3 / 13.4.3 (`composer.lock:10043`) |
| photoswipe | `5.4.4`, pinned exactly (`package.json:13`) | |
| tailwindcss, vite, laravel-vite-plugin | `^4.0.0`, `^8.0.0`, `^3.1` (`package.json:10-15`) | |

Velox history: `^0.27` via a symlinked `path` repo (`VELOX-MIGRATION.md:9`), then v0.28.1 from VCS (`VELOX-MIGRATION.md:17`), then v0.29.0 (`VELOX-MIGRATION.md:18`), now v1.1.0. The Kami brief says Velox is at 1.3; this site is locked two minors behind. Database: MariaDB 10.11 on Herd, database `sea_dev` (`VELOX-MIGRATION.md:9`).

## Content types and fields

Registered in `config/velox.php:13-17`: Page, Post, Gallery. Menus `primary` (depth 1) and `footer` (`config/velox.php:24-27`). No Locales: monolingual (`config/velox.php:34`). Display Time Zone `Europe/Zurich` (`config/velox.php:41`).

| Type | Labels | Prefix | Hierarchical | Orderable | Archive | Fields | Other |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `Page` | Velox default | `''` (`app/ContentTypes/Page.php:15-18`) | yes (`:20-23`) | yes (`:25-28`) | no (`:30-33`) | `RichText body`, Blocks: Gallery (`:57-62`) | `render()` override for the Home Page (`:36-55`) |
| `Post` | « Article » / « Articles » (`app/ContentTypes/Post.php:14-22`) | `articles` (`:24-27`) | no (`:29-32`) | no (`:34-37`) | yes (`:39-42`) | `RichText body`, Blocks: Velox `Video`, Gallery (`:55-60`) | RSS feed on (`:44-48`); Taxonomies Category, Tag (`:50-53`) |
| `Gallery` | « Galerie » / « Galeries » (`app/ContentTypes/Gallery.php:12-20`) | `photos` (`:22-25`) | yes (`:27-30`) | yes (`:32-35`) | no (`:37-40`) | `MediaRelation photos` images, multiple; `MediaRelation cover` images; `Textarea description` (`:52-59`) | SEO image from `cover` (`:42-45`), SEO description from `description` (`:47-50`) |

An album is a Gallery holding Galleries, with no photos of its own (`app/ContentTypes/Gallery.php:9`).

Taxonomies:
- `Category`: prefix `category`, hierarchical, multiple, optional, public (`app/Taxonomies/Category.php:9-35`). Imported flat (`app/Console/Commands/ImportCommand.php:112-113`).
- `Tag`: prefix `tag`, flat, multiple, optional, public (`app/Taxonomies/Tag.php:9-35`).

Imported counts (enforced by the import's check): 10 Posts, 5 Pages, 14 Galleries (4 root), 1,105 Media Items, 15 Folders, 4 Categories, 31 Tags, 5 primary + 1 footer Menu Items, 2 Accounts, 1,186 Redirects (`app/Console/Commands/ImportCommand.php:414-434`).

## Authors

- WordPress has two users. `psyao` (Steve) maps to the seeded Developer Account; `aline` gets a new Account; any other login throws (unhandled `match`) (`app/Console/Commands/ImportCommand.php:81-88`).
- Aline is created as a `Role::Writer` with `password => null`, name and email from `wp_users`, via `forceFill` because `role` is not fillable; no mail sent ("Invited") (`app/Console/Commands/ImportCommand.php:102-110`). The test asserts Writer role, null password and `AccountStatus::Invited` (`tests/Feature/ImportTest.php:35-43`) and that no mail or notification is sent (`tests/Pest.php:49-53`).
- The Developer comes from `DEVELOPER_*` env via `DeveloperSeeder` (`database/seeders/DeveloperSeeder.php:15-33`, `config/sea.php:6-10`).
- Authorship is Velox's `created_by` on each Entry (`app/Console/Commands/ImportCommand.php:337`). Steve authors 7 posts, Aline 3 (`VELOX-MIGRATION.md:11`). Pages, Galleries and « Accueil » are all authored by the Developer (`app/Console/Commands/ImportCommand.php:191-197, 209, 281`).
- Authors are not displayed anywhere on the public site: no view under `resources/views` references an author or `created_by` (grep returns nothing); the Post header shows Categories, title and date only (`resources/views/velox/post.blade.php:5-11`). There is no author archive; WordPress's `/?author=` answers 404 (`app/ContentTypes/Page.php:47-49`).
- `User` is Laravel's model plus Velox's `IsAccount` trait / `Account` contract (`app/Models/User.php:12-22`).

## Galleries

### Structure and import

- 4 root albums, in the Photos page's shortcode order: NextGEN album ids `[1, 8, 5, 6]` (Mariage, Valais 2012, Sorties, Divers); Vacances, Saint-Prex and Voyage de noce left out (`app/Console/Commands/ImportCommand.php:43-47`). 10 child galleries; per-gallery counts: Aperçu 4, Notre Mariage 718, Alpage de Bouzerou 28, Barrage du Zeuzier 126, Bisse de Savièse 70, Grande Dixence 69, Pyramides d'Euseigne 7, Zoo de Servion 56, Lakeparade 2012 14, Paysages 13 (`app/Console/Commands/ImportCommand.php:50-55`).
- Each Gallery: title unescaped (`stripslashes`) and entity-decoded, NextGEN slug, `parent_id` for children, published, `published_at` = earliest real `imagedate` (≥ 1970-01-02) parsed as Europe/Zurich then UTC; an album takes its galleries' earliest; `description` from `galdesc` (`app/Console/Commands/ImportCommand.php:239-242, 250, 255, 272-287`). 717 Notre Mariage pictures have a 1970-01-01 `imagedate` (`:239-240`).
- Siblings keep NextGEN's album `sortorder`; Gallery is orderable so each new Entry goes last (`app/Console/Commands/ImportCommand.php:249-257`, `VELOX-MIGRATION.md:13`).

### Photos: storage, order, captions

- Each picture is uploaded with `MediaLibrary::upload(new UploadedFile(...), imagesOnly: true, folder: $folder)` from `config('sea.gallery')/<basename(path)>/<filename>` (`app/Console/Commands/ImportCommand.php:298-303`); the root defaults to `~/Code/wp-dumps/steve-et-aline.ch/wp-content/gallery` (`config/sea.php:12-13`).
- Folders: `Galeries/<album>/<gallery>` (`app/Console/Commands/ImportCommand.php:243, 251, 256`).
- Order: NextGEN `sortorder` then `pid` (`app/Console/Commands/ImportCommand.php:297`), stored as the ordered `photos` MediaRelation (`:322`). Media Item names are `<Gallery title> <n>`, zero-padded so the Media Library's name sort keeps that order (`:305-306`).
- Cover: NextGEN `previewpic` (`app/Console/Commands/ImportCommand.php:323`). An album with no cover shows its first child's cover (`resources/views/blocks/gallery.blade.php:9, 20`; test `tests/Feature/ImportTest.php:543`).
- Captions/alt: no caption field is imported or rendered. `alt` is kept only for the 20 Zoo de Servion pictures whose alttext names the animal; all other alttexts repeated a file or gallery name and are dropped (`app/Console/Commands/ImportCommand.php:307-308`). Thumbnail links are therefore named by `aria-label="{{ $photo->name }}"` (`resources/views/blocks/gallery.blade.php:40-41`).
- Lakeparade 2012's featured image is NextGEN picture 806, attached as that post's `seo.image` (`app/Console/Commands/ImportCommand.php:170-175`).

### Conversions / thumbnails

- Velox's defaults (not overridden in `config/velox.php`): WebP variants generated at upload, never wider than the original: `xsmall` 320, `small` 640, `medium` 1024, `large` 1600, `full` 2560 (`vendor/psyao/velox/config/velox.php:35-37, 61-67`); originals kept on the private `local` disk, variants served from `public` (`vendor/psyao/velox/config/velox.php:40-41`); images over 40 MP refused (`:59-60`).
- Rendered with `<x-velox::image>`, which emits `srcset` of all variants, the largest as `width`/`height`, `loading="lazy"`, `decoding="async"` (`vendor/psyao/velox/resources/views/components/image.blade.php:7-22`).
- The site wraps it in `<x-photo>` to widen `sizes` for square and 4:3 crops, because "Velox's variants are named by width": each size is multiplied by `max(1, photo ratio ÷ box ratio)` (`resources/views/components/photo.blade.php:1-12`; tests `tests/Feature/GalleryTest.php:53`, `tests/Feature/AlbumTest.php:42`).
- No custom conversions exist in the site.

### The lightbox

- PhotoSwipe 5.4.4 (`package.json:13`), framework-free; Alpine and Livewire rejected for the public site (`docs/adr/0001-blade-tailwind-native-js.md:3-9`).
- Loaded only when a `.bento` grid is on the page, via dynamic `import('photoswipe/lightbox')` and its CSS, with the core as `pswpModule: () => import('photoswipe')` (`resources/js/app.js:1-5, 17`; ADR consequence `docs/adr/0001-blade-tailwind-native-js.md:13`). A test asserts PhotoSwipe is only in the manifest's `dynamicImports` of `app.js`, never its static `imports` (`tests/Feature/LightboxTest.php:18-25`).
- One lightbox per grid, children `a`, `bgOpacity: 1` (fully opaque `ink` backdrop) (`resources/js/app.js:16-17`; `DESIGN.md:68`).
- Each link carries `href` = `large` variant (no-JS fallback), `data-pswp-srcset` = full srcset, `data-pswp-width/height` = largest variant (`resources/views/blocks/gallery.blade.php:39-41`; `tests/Feature/LightboxTest.php:9-16`).
- Accessibility: controls' titles (also their accessible names) in French: « Fermer », « Zoom », « Photo précédente », « Photo suivante », error message (`resources/js/app.js:7-14`). On `afterInit` the site patches PhotoSwipe's hardcoded English ARIA: dialog `aria-label="Visionneuse de photos"`, scroll wrap `aria-roledescription="carrousel"`, slides `aria-roledescription="photo"` (`resources/js/app.js:19-25`). Focus ring inside the lightbox turns `paper` with `outline-offset: -4px` since `pink-dark` on `ink` is 2.9:1 (`resources/css/app.css:349-353`; `DESIGN.md:23`). PhotoSwipe CSS variables themed to the palette (`resources/css/app.css:340-348`).
- Grid DOM order = Tab order = lightbox order; no `grid-auto-flow: dense` (`resources/css/app.css:285-289`; `DESIGN.md:68`). A « Passer la galerie » skip link precedes every grid (`resources/views/blocks/gallery.blade.php:35, 49`; test `tests/Feature/GalleryTest.php:39`).

### Performance at 1,105 photos / 720 MB

- Source: 10 gallery folders, 1,105 originals, 720.9 MB (`VELOX-MIGRATION.md:9`).
- Measured upload through `MediaLibrary::upload()` in one transaction: 464 to 494 s, median 0.10 s and p95 1.3 s per picture, 113 MB peak; 723 MB originals + 324 MB variants (3,816 files). A rollback or `migrate:fresh` leaves files on both disks. Media Library and picker answer in 0.3 to 0.7 s at 1,105 items, 10 per page (50 max) (`VELOX-MIGRATION.md:10`).
- Full import with real pictures on v0.28.1: 466 s wall-clock, 445 s CPU, 133 MB peak (`VELOX-MIGRATION.md:17`). An earlier run took 6 h wall-clock with the Mac asleep (`VELOX-MIGRATION.md:13`).
- Local storage today: `storage/app/private/media` 723 MB / 1,105 files, `storage/app/public/media` 324 MB / 3,816 files (measured with `du`/`find`).
- Gallery pages are unpaged: Notre Mariage renders 718 thumbnails on one page (`VELOX-MIGRATION.md:13, 17`; `resources/views/blocks/gallery.blade.php:34`). On a Gallery's own page the first 5 thumbnails are eager and the first has `fetchpriority="high"`; all are lazy inside a Block (`resources/views/blocks/gallery.blade.php:2, 44-45`; tests `tests/Feature/GalleryTest.php:67, 79`). Album pages load their first 2 covers eagerly (`resources/views/blocks/gallery.blade.php:23`; `tests/Feature/AlbumTest.php:58`).
- Album cards cost two queries per card ("fine for an album's few Galleries") (`resources/views/blocks/gallery.blade.php:10`).
- `rule`-coloured placeholder behind each lazy image (`resources/css/app.css:316-321`).

## Blocks and rich text

| Block | Defined | Offered in | Fields | View |
| --- | --- | --- | --- | --- |
| `gallery` (« Galerie », `heroicon-o-photo`) | `app/Blocks/Gallery.php:11-45` | Page body (`app/ContentTypes/Page.php:60`), Post body (`app/ContentTypes/Post.php:58`) | `EntryRelation gallery` to Gallery, required (`app/Blocks/Gallery.php:29-34`) | `blocks.gallery` (`app/Blocks/Gallery.php:36-39`); admin summary is the Gallery's title (`:41-44`) |
| Velox `Video` | `vendor/psyao/velox/src/Blocks/Video.php:16` | Post body only (`app/ContentTypes/Post.php:58`) | Velox's | Velox's |

- The Block id `gallery` is stored in every node and must never change (`app/Blocks/Gallery.php:13`).
- Bodies are Velox `RichText` (TipTap documents). Imported Blocks sit at the document's top level only (`app/Console/Commands/ImportCommand.php:411-413`): 4 on the Photos page, 1 in Lakeparade 2012 (`:421-422`). Stored config is `{"gallery": <id>}` (`VELOX-MIGRATION.md:16`).
- Rendered with `{{ $entry->body }}` inside `.entry` (`resources/views/velox/page.blade.php:5`, `resources/views/velox/post.blade.php:12`); `.entry` typography: 1.125rem body, pink Fraunces drop cap, link underline colours, blockquote, etc. (`resources/css/app.css:175-274`).
- The Post row teaser reads the stored document's text nodes (not the render) so Blocks contribute nothing, then truncates at 220 characters on word boundaries (`resources/views/velox/partials/post-row.blade.php:11-18`).

## Templates and views

| View | Renders |
| --- | --- |
| `resources/views/velox/layout.blade.php` | Shell: Velox SEO tags (`:6`), SVG favicon (`:7`), `@fonts` + `@vite` (`:8-9`), skip link « Aller au contenu » (`:12`), « Aperçu » strip on previews (`:13-15`), masthead with heart, brand, tagline (`:16-22`), sticky band with `primary` Menu in a native `popover` below `md` (« Menu »/« Fermer » button) and `aria-current` marking (`:23-44`), `main#content` (`:46-48`), footer with `footer` Menu (`:50-60`) |
| `resources/views/velox/home.blade.php` | Home Page: visually hidden `h1`, 5 latest Post rows, « Tous les articles » button to the Archive (`:4-10`); data from `Page::render()` (`app/ContentTypes/Page.php:51-54`) |
| `resources/views/velox/page.blade.php` | Page: centred `h1`, body (`:4-5`) |
| `resources/views/velox/post.blade.php` | Post: Categories, `h1`, date in Display Time Zone with UTC `datetime` (`:6-10`), body, Tags as `#tag` pills (`:12-19`) |
| `resources/views/velox/gallery.blade.php` | Gallery: breadcrumb « Photos › ancestors » (`nav aria-label="Fil d'Ariane"`, Photos linked by path) (`:5-11`), `h1`, photo count (`:12-15`), then includes `blocks.gallery` with `eager => true` (`:17`) |
| `resources/views/blocks/gallery.blade.php` | Gallery content without title: description, then either album cards (child Galleries: 4:3 cover, title, « N photos »; `h2` title only inside a body) or the bento grid of photos (`:3-50`) |
| `resources/views/velox/archive/post.blade.php` | `/articles`: `h1` « Articles » + rows (`:4-5`) |
| `resources/views/velox/taxonomy/category.blade.php` / `tag.blade.php` | Term Archive: « Catégorie » / « Mot-clé » label, `h1` name (`#Name` for tags), rows (`category.blade.php:4-8`, `tag.blade.php:4-8`) |
| `resources/views/velox/partials/post-rows.blade.php` | Rows + paging; « Aucun article pour l'instant. » when empty; eager-loads `allTerms` (`:2-11`) |
| `resources/views/velox/partials/post-row.blade.php` | One dated row: big day numeral, month/year, Categories, `h2` link, teaser, « Lire l'article → » (`:2-20`) |
| `resources/views/velox/partials/categories.blade.php` | Categories as labels linking their Archives (`:2-8`) |
| `resources/views/velox/partials/paging.blade.php` | « ← Plus récents » / « Plus anciens → », page 1 at the Archive path not `?page=1`, nothing on one page (`:2-12`) |
| `resources/views/components/photo.blade.php` | `<x-photo>`: crop-compensated `sizes` around `<x-velox::image>` (`:1-12`) |
| `resources/views/components/heart.blade.php` | Inline SVG heart, `aria-hidden` (`:2`) |
| `resources/views/errors/404.blade.php` | French 404 in the normal layout, « Retour à l'accueil » (`:1-11`) |
| `resources/views/welcome.blade.php` | Laravel's stock welcome page, unrouted (no route references it; `routes/web.php:1-20`) |

## URL structure

### Public routes (from `php artisan route:list`)

Site routes, all `Route::permanentRedirect` (301), matched with or without trailing slash (`routes/web.php:5-8`):

| Pattern | Target | Source |
| --- | --- | --- |
| `page/2` | `/articles` | `routes/web.php:11` |
| `sitemap_index.xml` | `/sitemap.xml` | `routes/web.php:14` |
| `{name}-sitemap.xml` | `/sitemap.xml` | `routes/web.php:15` |
| `feed/{format}`, format ∈ rdf, rss, rss2, atom | `/feed` | `routes/web.php:19` |
| `{format}`, format ∈ rdf, rss, rss2, atom | `/feed` | `routes/web.php:20` |

Velox routes (registered as fallbacks, so the site's win) (`vendor/psyao/velox/routes/web.php:23-46`):

| Pattern | Handler |
| --- | --- |
| `GET robots.txt` | `RobotsController` (`vendor/psyao/velox/routes/web.php:34-36`) |
| `GET sitemap.xml` | `SitemapController` (`:38-40`) |
| `GET {path}` (`.*`) | `EntryController`: canonical 301 to lowercase/no trailing slash, Redirect lookup, Home Page, Archives, Term Archives, Entries, feeds (`vendor/psyao/velox/src/Http/Controllers/EntryController.php:29-63`) |
| `GET admin/preview/{token}` | `PreviewController` (`vendor/psyao/velox/routes/web.php:15-21`) |

Laravel/Filament: `up` health (`bootstrap/app.php:12`), `storage/{path}` (local disk `serve => true`, `config/filesystems.php:33-39`), Filament admin at `/admin` (`app/Providers/Filament/AdminPanelProvider.php:26-28`).

Resulting public URL space:
- `/` Home Page « Accueil »; its own path answers 301 to `/` (`vendor/psyao/velox/src/Http/Controllers/EntryController.php:71`).
- `/<page-slug>` Pages: flat slugs (prefix `''`), e.g. `/liste-de-mariage`, `/photos` (`VELOX-MIGRATION.md:12, 14`).
- `/articles` Post Archive, `?page=N` (404 past the last page) (`vendor/psyao/velox/src/Http/Controllers/EntryController.php:96-105`); `/articles/<slug>` Posts.
- `/articles/feed` and `/feed` RSS (`app/ContentTypes/Post.php:44-48`).
- `/category/<slug>`, `/tag/<slug>` Term Archives (`app/Taxonomies/Category.php:9-12`, `app/Taxonomies/Tag.php:9-12`).
- `/photos/<album>` and `/photos/<album>/<gallery>` Galleries, e.g. `/photos/mariage/notre-mariage` (`VELOX-MIGRATION.md:13`). Note `/photos` is the Page, and Galleries live under it only by their type prefix, not as its children (`resources/views/velox/layout.blade.php:30`).
- `/sitemap.xml`, `/robots.txt`.

### Old WordPress / NextGEN URLs

Recorded as Velox Redirects by `sea:import` (`redirectFrom()`), 1,186 in total (`app/Console/Commands/ImportCommand.php:432-434`):

| Former pattern | Target | Source |
| --- | --- | --- |
| `/<post-slug>/` and each non-empty `_wp_old_slug` (post 58) | the Post | `app/Console/Commands/ImportCommand.php:155-161` |
| `/<post-slug>/nggallery/image/<image_slug>` (14, Lakeparade 2012) | the Post | `:163-168` |
| `/<category-slug>/` (Yoast stripped the base) | the Category | `:132-136` |
| `/photos/nggallery/album/<gallery-slug>` | the Gallery | `:316` |
| `/photos/nggallery/album/<gallery-slug>/page/<N>` (21 a page, ≤500 shown; 37 pages) | the Gallery | `:315-320` |
| `/photos/nggallery/album/<gallery-slug>/image/<image_slug>/` (all 1,105, incl. Notre Mariage's 218 beyond 500) | the Gallery | `:311-312` |
| `/ngg_tag/<slug>/` (5) | Lakeparade 2012 Gallery | `:260-267` |

Handled without a Redirect row:
- `/category/<slug>/`, `/tag/<slug>/`, pages' `/<slug>/`: Velox's trailing-slash 301 (`app/Console/Commands/ImportCommand.php:132-133`; `VELOX-MIGRATION.md:15`).
- `/?feed=rdf|rss|rss2|atom` → 301 `/feed` (`app/ContentTypes/Page.php:42-45`).
- `/?p=`, `/?page_id=`, `/?attachment_id=`, `/?feed=` (other values, e.g. comments-rss2), `/?s=`, `/?m=`, `/?author=` on the Home Page → 404 (`app/ContentTypes/Page.php:47-49`).
- `/photos/?album=…&gallery=…` lands on `/photos` via the canonical 301 (query kept) (`VELOX-MIGRATION.md:15`; `vendor/psyao/velox/src/Http/Controllers/EntryController.php:130-135`).
- Everything else (date/author archives, `/search/…`, embed, trackback, comment-page, attachment pages, NextGEN variants, `/wp-content/gallery/…`, `/wp-content/uploads/…`) → 404, after the canonical 301 when slash-ended or uppercase (`VELOX-MIGRATION.md:15`; test `tests/Feature/ImportTest.php:430`).

Internal links in bodies are rewritten to host-less Velox paths; NextGEN `?gallery=N` links go to gallery N; an unknown target throws (`app/Console/Commands/ImportCommand.php:384-404`). Test replays 1,233 former URLs, each a single 301 to a 200 (`VELOX-MIGRATION.md:15`; `tests/Feature/ImportTest.php:341`).

## Media handling and storage

- Velox Media Library on two disks: `public` (`storage/app/public`, served via `/storage`) for variants and `local` (`storage/app/private`) for originals (`vendor/psyao/velox/config/velox.php:40-41`; `config/filesystems.php:33-48`).
- Volumes: 723 MB originals (1,105 files), 324 MB variants (3,816 files) (`VELOX-MIGRATION.md:17`, confirmed on disk).
- `wp-content/uploads` was near empty; only NextGEN's `wp-content/gallery` carried pictures (`VELOX-MIGRATION.md:9`). The two `[caption]` images pointed at gone uploads (`app/Console/Commands/ImportCommand.php:369`).
- Tests fake both disks with `Storage::persistentFake` and stand-in 4×3 (every tenth 3×4) JPEGs (`tests/Pest.php:28-30, 58`).

## The WordPress / NextGEN import

Command `sea:import` (`app/Console/Commands/ImportCommand.php:36`).

Sources:
- WordPress DB via the `wordpress` connection: MariaDB, `wp_sea`, prefix `wp_kryhoa_`, `strict => false` (`config/database.php:89-100`; `.env.example:30-34`).
- NextGEN files at `config('sea.gallery')` (`config/sea.php:12-13`).
- Tables read: `users`, `posts`, `postmeta`, `terms`, `term_taxonomy`, `term_relationships`, `options`, `links`, `ngg_album`, `ngg_gallery`, `ngg_pictures` (`app/Console/Commands/ImportCommand.php:83, 122, 150, 156, 179, 203, 227, 247, 254, 297`).

Order: Galleries, pages, terms + posts, home + menus, count check (`app/Console/Commands/ImportCommand.php:89-94`).

Transformations:
- Bodies: `[caption]…[/caption]` removed whole; `[album id=N …]` and `[nggallery id=N]` → `<div data-type="customBlock" data-id="gallery" data-config='{"gallery":id}'>`; WordPress 7.1.3's `wpautop()` copied verbatim (`app/WordPress/formatting.php:3-4, 21`); `h4` → `h3`; empty `&nbsp;` paragraphs dropped; then Velox `fromHtml()`, which drops `<!--more-->` and inline `text-align` (`app/Console/Commands/ImportCommand.php:361-381`). No `wptexturize` (`:362`).
- Titles, term names, options: `html_entity_decode` (`app/Console/Commands/ImportCommand.php:444-447`).
- Dates: `post_date_gmt` → `published_at` and `created_at`, `post_modified_gmt` → `updated_at` (`app/Console/Commands/ImportCommand.php:331-342`). Gallery dates from EXIF `imagedate` in Swiss time (`:280`).
- Terms: per post, ordered by name as `get_the_terms()` lists them (every `term_order` is 0) (`app/Console/Commands/ImportCommand.php:177-186`).
- SEO: Yoast `_yoast_wpseo_metadesc` kept in `seo.description`; Yoast titles dropped (`app/Console/Commands/ImportCommand.php:348-359`). « Accueil » gets `seo.title` « blogname - blogdescription » (`:211`).
- Home Page « Accueil » is new; its body is `blogdescription` (`app/Console/Commands/ImportCommand.php:200-218`).
- Menus: `primary` = « Accueil » + the 4 pages in order; `footer` = the single blogroll link with `new_tab` from `link_target` (`app/Console/Commands/ImportCommand.php:220-229`).

Idempotency: none in the upsert sense. The command refuses to run when any Entry or Media Item exists ("run `migrate:fresh --seed` first") (`app/Console/Commands/ImportCommand.php:67-71`), then deletes `media/` on both disks as orphans (`:73-76`), and writes everything in one DB transaction ending in a count check that throws and rolls back on mismatch (`:80-95, 408-442`). Uploaded files are not rolled back with the transaction (`VELOX-MIGRATION.md:10`), hence the pre-clean. Tested: second run refused with nothing changed on disk (`tests/Feature/ImportTest.php:175`), media cleared first (`:188`).

Legacy IDs: not persisted. WordPress/NextGEN ids exist only in memory during the run (`$nextgen` by album id / gid, `$media` by pid, Terms by `term_taxonomy_id`) (`app/Console/Commands/ImportCommand.php:57-63, 115-119, 145-146`). The only durable trace of former identity is the Redirect paths.

Known losses:
- Unpublished posts/pages: only `post_status = publish` imported (`app/Console/Commands/ImportCommand.php:150, 193`).
- `[caption]` blocks and their caption text (posts 17, 47) (`:369-370`).
- Yoast title overrides (`:348-349`).
- NextGEN alt texts except 20; no captions/descriptions per picture (`:307-308`).
- Albums Vacances, Saint-Prex, Voyage de noce (`:44-47`).
- The other post's featured image (attachment gone) (`:170`).
- 717 Notre Mariage `imagedate`s at 1970-01-01 (ignored for dating) (`:239-240`).
- Sidebar widgets (search, recent posts, categories, links) (`DESIGN.md:48`); no comments or search on the site (`docs/adr/0001-blade-tailwind-native-js.md:3`).
- Author, date archives, attachment pages, NextGEN image variants: 404 (`VELOX-MIGRATION.md:15`).
- Admin round-trip adds `description: null` and a full `seo` object to saved rows; values read the same (`VELOX-MIGRATION.md:27`).

## Design and accessibility

- Direction: variant D of nine prototypes, editorial, keeps the Heartland theme's hot pink and hearts (`DESIGN.md:3-5`).
- Tokens as Tailwind `@theme`: paper `#fbf9f7`, ink `#2b2528`, pink `#e50780`, pink-dark `#d20775`, blush `#fdf0f6`, mute `#6f6469`, rule `#ece4e6` (`DESIGN.md:11-19`; `resources/css/app.css:7-16`).
- WCAG AA contrast work: `mute` darkened from the prototype's `#857a80` (3.9:1) to `#6f6469` (5.4:1 on paper); `#e50780` is 4.3:1 on paper / 4.1:1 on blush / 4.5:1 under white, so small pink text uses `pink-dark` (5.0:1 paper, 4.7:1 blush, 5.2:1 under white) (`DESIGN.md:21`).
- Focus: 2px solid `pink-dark` `:focus-visible` outline at 2px offset following corners; `::selection` white on `pink-dark` (5.2:1); blush selection rejected at 1.05:1 (`DESIGN.md:23`; `resources/css/app.css:49-57`).
- Fonts: Fraunces (400, 600, roman + italic) and Instrument Sans (400–600), downloaded at build time by laravel-vite-plugin's `bunny()` and written by `@fonts([...])`; nothing loads from a third-party host at runtime (`DESIGN.md:29-31`; `vite.config.js:3, 11-19`; `resources/views/velox/layout.blade.php:8`). Tested: every `<link>`/`<script>` URL is same-origin (`tests/Feature/LayoutTest.php:59-68`).
- Skip link to `main` (`tabindex=-1`, scroll margin under the sticky band) (`resources/views/velox/layout.blade.php:12, 46`; `DESIGN.md:47`). Mobile menu button padded to a 44px touch target (`DESIGN.md:46`; `resources/views/velox/layout.blade.php:27`). `aria-current="page"` or `"true"` for the section (`resources/views/velox/layout.blade.php:31-39`).
- Motion: CSS only, 150–250 ms, all off under `prefers-reduced-motion`: `@view-transition` cross-fade, `@starting-style` menu fade, link colour fades, a one-shot heart beat (`DESIGN.md:53-60`; `resources/css/app.css:18-21, 35-36, 128, 144`).
- Bento grid: 6 columns ≥1000px, every ninth photo 4×2, others 2×1, bleeding 6rem past the column; 2 columns below (`DESIGN.md:68`; `resources/css/app.css:290-337`).
- Visited links in bodies only: underline turns `mute` (`DESIGN.md:41`).
- Favicon: SVG heart only; no `favicon.ico` (`DESIGN.md:50`; `tests/Feature/LayoutTest.php:63-67`).
- Stack: plain Blade + Tailwind 4 + native ES modules from one `app.js`; Livewire stays in the admin; Inertia rejected because Infomaniak runs no Node (no SSR) (`docs/adr/0001-blade-tailwind-native-js.md:3-9`). Assets built off-server (`:14`).

## Work-arounds for Velox

Custom code in the site that exists because Velox lacked (or lacks) something:

| What | Where | Why |
| --- | --- | --- |
| Verbatim copy of WordPress's `wpautop()` and helpers (312 lines, excluded from Pint) | `app/WordPress/formatting.php:3-4` | Velox's `fromHtml()` doesn't paragraph WordPress's newline-formatted content |
| Whole importer (shortcode → Block, relinking, redirects, count check) | `app/Console/Commands/ImportCommand.php` | No generic WordPress/NextGEN importer in Velox |
| Home Page rendering the 5 latest posts, WP query-string feed 301 and 404s | `app/ContentTypes/Page.php:35-55` | Velox's Home Page is a Page with no "latest posts" mode and no query-string legacy handling |
| Legacy redirects as Laravel routes for paths no Entry/Term holds | `routes/web.php:5-20` | Velox Redirects target an Entry or Term only |
| Menu "section" current state by URL prefix | `resources/views/velox/layout.blade.php:30-38` | Comment: "use Velox once it marks an Entry's ancestors' items" |
| Breadcrumb links the Photos Page by hard-coded path | `resources/views/velox/gallery.blade.php:6-7` | Galleries are not children of the Photos Page (type prefix only) |
| `<x-photo>` crop-aware `sizes` | `resources/views/components/photo.blade.php:1-12` | Velox variants are width-named; no crop-aware sizes helper |
| Teaser from raw TipTap text nodes | `resources/views/velox/partials/post-row.blade.php:11-18` | No excerpt/teaser helper that excludes Blocks |
| Page 1 link at the Archive path, not `?page=1` | `resources/views/velox/partials/paging.blade.php:5` | Paginator's default previous URL |
| Count of Redirects through `DB::table('velox_redirects')` | `app/Console/Commands/ImportCommand.php:433-434` | "Velox's internal table, read only: no model to count with" |
| MariaDB session time zone `+00:00` | `config/database.php:69-70` | Friction 2: TIMESTAMP columns read in the server's zone; became a Velox docs fix (`VELOX-MIGRATION.md:25`) |
| PhotoSwipe ARIA patched to French | `resources/js/app.js:19-25` | PhotoSwipe (not Velox) hardcodes English announcements |
| `DeveloperSeeder` from env | `database/seeders/DeveloperSeeder.php:15-33` | First Account bootstrap |

Frictions logged and their verdicts: Media Picker empty state untranslated (Velox gap, fixed 0.28.0); MariaDB time zone (Velox docs gap, fixed 0.28.1); `velox:install` output failing Pint (Velox gap, fixed 0.28.1); admin save adding nulls (not a gap) (`VELOX-MIGRATION.md:24-27`). An earlier site-side breadcrumb walk was replaced by Velox's `Entry::ancestors()` (commit `5e5f1a7`; now `resources/views/velox/gallery.blade.php:8`).

## Tests

Pest 5 Feature tests run against the real `wp_sea` into `sea_test`, importing once per process (never `--parallel`) (`tests/Pest.php:16-56`). Files: `ImportTest.php` (25 tests: content, authors, order, bodies, Blocks, links, 1,233 redirects, 404s, feeds, admin save round-trip), `GalleryTest.php` (7), `AlbumTest.php` (4), `LightboxTest.php` (2), `LayoutTest.php` (8), `PostRowsTest.php` (6), `PostAndPageTest.php` (2), `DisplayTimeZoneTest.php` (1), `ImportedSiteTest.php` (2) (test names at `tests/Feature/*.php`, e.g. `tests/Feature/ImportTest.php:28-543`).
