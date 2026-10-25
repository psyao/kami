# Velox retrospective

What the Velox years teach Kami, what Velox lacks against the comparatif's MVP, and how the three Velox sites move to Kami. Kami is a rewrite from zero: Velox is a source of evidence, not a starting design. Written for phase 2 from the notes in `docs/research/`; the raw sources were reopened only to check a point (each check is cited). This document states directions, not decisions: each direction names the wayfinder ticket that will settle it, and the ones that are hard to reverse will get an ADR there.

Note citations: `adr` = `docs/research/velox-adr.md`, `fr` = `docs/research/velox-frictions.md` (friction IDs `A`, `P`, `S`, `G`, `I-`, `D`), `aguet` / `psyao` / `sea` = the three site notes, `mut` = `docs/research/mutualise.md`.

## 0. Baseline: the sites run Velox 1.1.0, not 1.3.0

The brief describes Velox 1.3. All three sites lock **v1.1.0** (aguet §2, psyao §2, sea "Versions"). Velox 1.2.0 and 1.3.0 were both released on 2026-10-25 (`~/Code/velox/CHANGELOG.md:5-28`, checked), so nothing they added has met a real site:

| Release | Feature | Field evidence |
| --- | --- | --- |
| 1.2.0 | Revisions of Entries, browser autosave | none |
| 1.2.0 | Focal point on images | none; sea still compensates crops by hand (`<x-photo>`, sea "Conversions") |
| 1.2.0 | Shareable preview links | none |
| 1.2.0 | Entry and Media links picked from a body; Replace file on a Media Item | none |
| 1.3.0 | Fields and editable SEO on Terms (ADR 0018) | none |
| 1.3.0 | Site full-text search (`velox_search`) | none; no site has a search (psyao §9, sea "Known losses") |
| 1.3.0 | Global sets | none |
| 1.3.0 | Excerpt fix for punctuation after a link (I-461) | sea keeps its own teaser |
| `main`, unreleased | starter search view (G63), docs fix (G64) | none |

Consequences for this retrospective:

- **Already fixed in Velox and proven on a site** (fixed in ≤ 1.1.0): every friction in `fr` whose status is "Fixed in 0.x" or "1.0/1.1". Kami must not regress on them; they feed the lessons in §1.1, not the gaps.
- **Fixed in Velox but unproven** (1.2, 1.3): treated as designs to reread, not as proven decisions.
- **Still open in Velox 1.3**: A8 (public middleware group), A16 (public Settings API), P16 (public Redirect API), I-220a/b (Menu parent field, `?p=` permalinks), G56, G58, G61, G62, A18 (`fr` "Counts").

## 1. What to carry over

Kami starts from zero. Velox's ADRs are **evidence, not defaults**: every one of them is reopened below and answered again in the wayfinder tickets, against the brief and the comparatif, with Velox's field record as one input among others. What carries over unconditionally is the lessons: needs and failure modes the three sites proved, independent of how Velox solved them.

### 1.1 Lessons proven by the sites

Requirements any Kami design must meet, whatever it decides:

- **Content model in code, content in the database, and no silent loss.** A Field renamed or removed in code silently dropped stored values on the next save (psyao/velox#283); no system can tell a rename from remove + add (psyao/velox#284). Whatever the storage, schema changes need an explicit migration path and a drift check that can fail a deploy (aguet runs one, aguet §8.5).
- **A URL never breaks.** Every path change must leave a redirect, without chains. Replaying every former URL is the acceptance test that worked: 558 for psyao, 1,233 for sea, one 301 to a 200 each (psyao §6, sea "Old WordPress URLs").
- **WordPress content must convert into the editor's format**, sanitised by structure rather than by a purifier, with `<code>`/`<pre>`, embeds and gallery shortcodes surviving (P1, P3, P4; psyao §8, sea "Transformations").
- **Multilingual means per-language URL, slug, status, SEO and publication**, with some values shared across languages (aguet's contact fields, untranslated Tags, I-320) and a fallback when a translation is blank (aguet §3).
- **All three sites use** hand ordering, hierarchy, a singleton Home, per-type model methods, Home pages that pull other content (aguet §10, psyao §10, sea "Work-arounds").
- **Filament churns.** Three Admin refreshes in one minor (0.21) and a tree plugin with its own audit (I-268) confirm the brief: the core must not depend on Filament.
- **The public surface must be explicit early.** An audit at 0.x found 98 classes, many public by accident (adr, ADR 0015).
- **Shared-hosting traps already paid for:** MariaDB `TIMESTAMP` read in the server zone (S2); Infomaniak answering `/robots.txt` itself (A22); Laravel's `.htaccess` trailing-slash rule causing double 301s (P15); the panel open to every user when a user model overrides access (A4, security); a reinstall overwriting files the site removed (A7); JSON key reordering on MySQL (A15).
- **Strict lazy loading from day one:** turned on late, it found 381 lazy loads (I-286).
- **Importer hygiene:** file writes escape database transactions (P11); a migration that calls models breaks fresh installs later (A9, A10); imports must carry original dates (A23).
- **Process:** a playground site where each feature is tried before release, and a migration log per site with a verdict per friction, gave 138 classified frictions (`fr` "Counts").

### 1.2 Velox's decisions, reopened

Each row is a question Kami answers from zero. "Evidence" is what the sites showed, for and against; "Also weigh" lists options Velox rejected or never considered that deserve a fresh look.

| Velox ADR | Velox's answer | Question for Kami | Evidence | Also weigh | Ticket |
| --- | --- | --- | --- | --- | --- |
| 0001 | Composer package, Filament plugin, private VCS repo | How is Kami distributed? | The plugin shape let aguet keep its own Resources beside Velox's (aguet §8.7); a private repo put a Composer token on a shared host (A24) | Starter kit, package + starter kit (brief's lean), monorepo; Packagist | distribution |
| 0002 | One `entries` table for every type, Field values in JSON | Where do field values live? | Uniform routing, sitemap and listings; fine at a few thousand rows. Against: no DB constraints, MySQL key reordering (A15, D8), `JSON_SET` migrations that exclude PostgreSQL (§2.9) | Table per type, typed columns + JSON, generated columns, EAV | content modelling |
| 0003, 0009 | Own Field abstraction mapped to Filament; Site subclasses of the Entry Resource as escape hatch | How does a developer declare fields without tying the core to Filament? | The 0.8 version without escape hatch "blocked adoption even by its author" (I-100/104); the subclass fixed it | Filament schemas in the admin layer only, with a core schema for storage; code generation | content modelling (prototype) |
| 0004 | Rich text stored as TipTap JSON, rendered at read time | How is rich text stored and sanitised? | Enabled `->code()`, image variants by id, `fromHtml()` imports. Against: search needs text extraction (1.3), tiptap-php parser quirks (P4), Filament cast bugs (I-430, G56) | HTML + sanitiser, Markdown, a block-document format | editing |
| 0005, 0018 | Terms in their own table; Fields added to Terms in 1.3 | Are taxonomies their own model or a content type? | "No Fields on Terms" was reversed within two weeks (ADR 0018) | Terms as entries of a taxonomy type, polymorphic tags | domain model |
| 0006 | Full public path stored on each Entry; redirects point at the model | How are URLs resolved and kept? | One indexed lookup; no chains. Against: descendant rewrites, a command for prefix changes, uniqueness checked in PHP with row locks (I-243), no site key | A separate URL/route table, request-time resolution | routing |
| 0007 | Fixed WebP variants generated at upload | When are image conversions made? | See §2.2 | On-demand conversions written as static files (brief) | media |
| 0008 | An Entry becomes a response through a `render()` hook | What is the rendering seam? | Fitted all three Home pages | Controllers per type, view-resolution conventions, view models | rendering |
| 0010 | Optional Entry subclass per type over the shared table | How do types get their own methods and relations? | Used by aguet and sea | Depends on storage (0002): a model per type | domain model |
| 0011 | `position` column per sibling group | How is hand order stored? | Used by all three sites | Nested set, sortable package | domain model |
| 0012 | Migrations squashed during 0.x under an upgrade floor | What is the migration policy before 1.0? | Three squashes, each a breaking upgrade floor | Never squash; squash only at 1.0 | release policy |
| 0013 | A Translation is its own Entry row per Locale | How is multilingual content stored? | Per-language URL and publication for free. Against: per-Locale slugs (A11), no per-Field fallback, values lost when a Field turns untranslatable (ADR 0016) | JSON per field (the comparatif's brique), a translations table | multilingual |
| 0014 | Blocks only as nodes inside rich text | Is the block model prose-first or layout-first? | Fits psyao and sea. Against: no page builder, an MVP feature (§3) | Builder field, block document, both | editing |
| 0015 | Public surface = what the docs name; the rest `final` + `@internal`, snapshot test | How is the public API kept narrow? | Made 1.0 possible | An `Internal` namespace (CLAUDE.md wants internals kept apart), `@api` tags | code structure |
| 0016 | Explicit declarative Field migrations; unknown values kept; check command | How does the schema evolve? | Fixed silent loss (#283) | Depends on storage: with typed columns, Laravel migrations do it | content modelling |
| 0017 | UTC storage, Display Time Zone macro | How are dates stored and shown? | Removed per-site duplication (I-417) | Casts, a view helper | domain model |
| 0018 | Picks stored as ids; Media uses in a morph-keyed index | How are references between content tracked? | Powers "Used by" and delete warnings (I-280) | One reference table for every holder and target | domain model |

Rules Velox kept outside ADRs (access by Capability, never Role; no sign-up; Trash never emptied automatically; Velox's routes as fallbacks; uploads checked by content MIME, SVG refused, EXIF stripped) are reopened the same way in the roles, routing and media tickets.

## 2. Where Velox fell short

What blocked, cost time, or contradicts the brief. Each item ends with the wayfinder ticket that settles it.

### 2.1 Multisite is excluded; the brief wants it in the data model

`~/Code/velox/docs/updating.md:41` (checked): "Velox serves one Site per Laravel app … and will not support multisite". No Velox table carries a site key, and path uniqueness is site-wide (ADR 0006).

**Departure from Velox:** Kami puts a site key in the data model from the first migration, because the brief makes multisite a V1 feature that must ship without a heavy migration. Concretely the key reaches every row whose identity or URL is per site: entries, terms, paths and redirects (uniqueness per site + path), menus, global sets, settings; media are a decision of their own (shared library or per site). One installation still serves one site in the MVP. Ticket: domain model.

### 2.2 Image variants generated at upload; the brief wants on-demand conversions

ADR 0007 generates fixed named-width WebP variants synchronously at upload. Field evidence:

- sea uploaded 1,105 photos in 464–494 s (median 0.10 s, p95 1.3 s per picture, 113 MB peak), producing 3,816 variant files (324 MB) whether or not a width is ever requested (sea "Performance").
- Renaming or changing the variant list means `velox:media:regenerate` over the whole library (G34).
- Width-named variants pushed sea to compute crop-aware `sizes` itself (`<x-photo>`).
- No AVIF; videos refused (`media.md:87`, checked).

Velox's reason still stands: serving an image must not cost PHP on every request (ADR 0007 rejected Glide for that). Direction: generate a conversion on its **first request**, write it under `public/` as a static file, and let Apache's `!-f` rewrite rule serve every later request without PHP (mechanism in `mut` §4). Conversions are named presets or signed URLs, never free parameters (Glide's mass-resize warning, `mut` §6.4). AVIF and WebP are detected at runtime with a fallback (`mut` §6.2-6.3): on aguet's host GD has WebP but not AVIF, while Imagick lists AVIF (§5.1). Ticket: media.

### 2.3 Imports are neither replayable nor traceable

psyao's and sea's importers refuse to run unless the tables are empty, run everything in one transaction, and keep no legacy id: WordPress ids live only in memory, and the former URLs survive only as Redirects (psyao §8 "Idempotency", "Legacy IDs"; sea "Idempotency", "Legacy IDs"). Uploaded files escape the transaction, so sea deletes `media/` on both disks before each run (P11). sea's full import takes 466 s, far beyond aguet's web `max_execution_time` of 60 s and even the 300 s ceiling (`mut` §9.1, §5.1), so it can only run locally or over SSH.

Direction, after matisse's practice (brief): a legacy-id mapping (`source`, `legacy_id` → model), upserts so a run can be replayed against a fresh dump, and work cut into batches that resume. File writes are tracked so a failed batch can clean up. psyao and sea are still live on WordPress, so this is also what makes their final migration safe (§4). Ticket: migration; the generic WordPress importer stays V1.

### 2.4 aguet's public contact form uses Livewire; the brief says Blade without Livewire

aguet mounts `<livewire:contact-form />` in a modal on its public page, polls `refreshDelivery()` every second up to 12 times, and puts rate limiting, honeypot and minimum fill time inside the component because route `throttle` does not reach Livewire's update endpoint (aguet §8.1). sea's own ADR rejected Livewire and Alpine on the public site (sea "Design and accessibility").

Direction: the public front of a Kami site ships no Livewire. aguet's form becomes a Blade form posting to a controller with route throttling and the honeypot, delivery stays DB-first plus `defer()`, and the per-rail progress animation either goes or becomes progressive JavaScript over a small JSON endpoint. That is a visible change on a production site, so it needs Steve's decision in the aguet migration spec. Simple forms are V1 in the comparatif, but aguet needs one, so by the brief's rule they enter the MVP (§3). Ticket: rendering; forms.

### 2.5 No scheduler, and the 15-minute web call does not fit Laravel's

Velox has no scheduled task, so aguet built `/cron/{token}`: a token route outside the `web` group that takes a lock and runs `Artisan::call('contact:notify')` directly, not `schedule:run` (aguet §8.2). Its `Schedule::command(...)->everyFifteenMinutes()` entry is inert in production. Two facts explain why calling `schedule:run` from that URL would not work either:

- `schedule:run` has no catch-up: a task is due only if the current minute matches its expression (`mut` §2). An `hourly()` task runs only when the 15-minute call lands on minute 0, and whether Infomaniak aligns its calls to the clock is not documented.
- `Schedule::command()` and `exec()` tasks spawn a child process through `proc_open`, which Infomaniak disables by default (`mut` §1.6, §2, §9.1). `Schedule::call()` and `job()` run in-process.

The notes also contradict each other on aguet's host: `DEPLOY.md:41` says `proc_open` "must be enabled" for Composer, while `app/Livewire/ContactForm.php:19` and `docs/explanation-contact-pipeline.md:62` say it is disabled (all three checked). Both are right: `proc_open` is disabled on the web SAPI and enabled on the CLI (§5.1).

Direction: Kami's hybrid scheduler (brief) keeps its own "last run" per task and runs a task when it is **overdue**, not when the minute matches, and runs tasks in-process only. The same tick drains the database queue within a time budget. aguet's deploy script also runs `queue:restart` with no worker to restart (aguet §8.5): harmless, but a sign the shared-hosting path needs its own documented recipe. Ticket: execution modes matrix.

### 2.6 Two fixed roles and no ownership

Velox has exactly two Roles, Developer (everything) and Writer (`ManageEntries`), three Capabilities, and no content ownership: any Writer acts on any Entry (`~/Code/velox/docs/accounts.md:66-95`, checked). The comparatif's MVP wants customisable roles and permissions per content type and action. sea already has a second author invited as a Writer (sea "Authors"). The question is open: roles defined in code, per-type capabilities, and whether checks target capabilities rather than roles (Velox's rule). Ticket: roles and permissions.

### 2.7 Security defaults are recipes, not defaults

- CSP is a documented recipe (`routing.md:239`, checked); aguet appended its own middleware to the whole `web` group and filtered it by route name (A8, still open). The CSP aguet ended with allows `'unsafe-inline'` and `'unsafe-eval'` for Alpine (aguet §8.6).
- No audit log.
- A4 (the user model's `canAccessPanel()` opening the Admin to every user) shipped until 0.24.

The brief's first principle is "secure with no configuration". Kami needs a public middleware group of its own with security headers and a CSP on by default, and an audit log of sensitive actions. Ticket: security (likely folded into routing and roles).

### 2.8 Routing: redirects, legacy query strings, Locale outside content pages

- A Redirect can only target an Entry or Term: psyao and sea moved every other former URL (`/blog`, Yoast sitemaps, paged lists, feeds) into `routes/web.php` (P12; psyao §6; sea "Public routes").
- WordPress query-string URLs (`?p=`, `?feed=`, `?author=`) are handled inside the Home Page's `render()` on both blogs (psyao §10, sea "Work-arounds"); `redirectFrom()` refuses `/?p=` (I-220b, open).
- An unknown non-canonical path answers a canonical 301 before its 404 (P13, kept by design).
- The Locale is set only on Velox's own pages, so aguet's error pages re-derive it from the first URL segment (aguet §8.3).
- `/robots.txt` must exist as a static file on Infomaniak, which otherwise answers its own (A22).

Direction: Redirects may target a model or a path, legacy query strings are matched like paths, and the Locale is resolved by middleware for every request, error pages included. Ticket: routing and URL resolution.

### 2.9 Database portability is narrower than the brief

Velox supports MariaDB, MySQL and SQLite; CI tests MariaDB 10.11 only and "MySQL is not tested" (I-346, `hosting.md`, checked). PostgreSQL is not supported, and the Field migration operations use `JSON_SET`/`JSON_REMOVE` (`hosting.md`, checked). MySQL's JSON key reordering caused a false "URLs changed" warning on every Admin page (A15) and needs a special test assertion (D8). The brief requires SQLite, MySQL/MariaDB and PostgreSQL behind an abstraction, tested in CI. Ticket: content modelling (storage) and test strategy.

### 2.10 Third-party Filament plugins cost the most Admin bugs

`solution-forest/filament-tree` produced its own UI audit (I-268, T1-T6, U1-U9) and two "action does nothing" bugs (G26, G47). Three Admin refreshes landed in one minor (0.21), which is why ADR 0015 narrowed what Admin extension points promise. Every Kami dependency is justified per the brief anyway; the evidence says Filament plugins deserve the hardest look. Ticket: dependencies (architecture table).

### 2.11 Smaller gaps to carry into the design tickets

| Gap | Evidence | Ticket |
| --- | --- | --- |
| No public API to seed site settings or list Redirects (`Setting`, `Redirect` are `@internal`) | A16, P16 (open) | extension points |
| `velox:install` has no `--email`/`--name`/`--password` | I-120 | distribution |
| `$entry->url()` is absolute from `APP_URL`; imports must store paths | P9 | routing |
| No teaser that skips Blocks; no menu "ancestor is current" marking | sea "Work-arounds" | rendering, menus |
| No inline Markdown for plain text fields | aguet §4, §10 | content modelling |
| Raw Filament keys in a French Admin; Laravel validation stays English | A14, I-195 | admin i18n |
| Private VCS repository: a Composer token on a shared host | A24 | distribution, licence |
| Backup is site code, and dumps no media | A17-A19, A18 (open) | V1 backups |

## 3. What Velox lacks against the comparatif's MVP

Legend: **✗** missing, **~** partial, **?** not verified in the notes or docs. Features Velox covers are not listed (custom content types in code, Fields, repeaters, relations, taxonomies, hierarchy, singletons/Global sets, rich text, custom Blocks, internal link picker, Media Library with metadata and folders, srcset, drafts, scheduled publication, Trash, duplication, preview, revisions and share links (1.2, unproven), Admin search, 2FA, multilingual entries, slugs, hierarchical and prefixed URLs, Redirects with auto-redirect on slug change, canonical, Blade layouts and components, SEO meta, Open Graph, sitemap, robots, hreflang, menus with locations, `make:*` commands, install command, Filament dashboard, dark mode, French Admin). The **Sites** column names the reference sites that need the feature; "none" makes it a candidate for V1 under the brief's rule.

| MVP feature | Velox | Sites | Note |
| --- | --- | --- | --- |
| Block editor / page builder | ✗ by design (ADR 0014: Blocks only inside rich text) | none | Candidate for V1; agency sites will need it |
| Conditional fields, layout fields (tabs, sections) | ~ only by chaining Filament in a Site Resource subclass (ADR 0009) | none directly | Belongs in the Field abstraction |
| Stable UUID links | ~ integer ids; ULIDs on media paths only | none | |
| Quick create of a related entry | ? | none | |
| On-demand image transformations | ✗ at upload (§2.2) | sea | |
| AVIF | ✗ WebP only | none | Cheap once runtime detection exists |
| Video / audio files | ✗ refused (`media.md:87`) | none (videos are embeds) | |
| Chunked uploads | ✗ (not documented in Filament or Livewire either, `mut` §7) | sea (720 MB, but imported by CLI) | |
| Mandatory alt text | ~ flagged, optional | sea (most alts dropped on import) | Brief's accessibility principle |
| Last modifier tracking | ~ `created_by` only | none | |
| Customisable roles, granular permissions | ✗ (§2.6) | sea (two authors) | |
| User enumeration protection | ? | — | |
| Field-level translation, fallback Locale | ~ entry-level with untranslated Fields; no per-Field fallback | aguet | |
| Interface strings translated from the Admin | ✗ | none | aguet keeps UI strings in `lang/` |
| Multilingual URLs by domain or subdomain | ? prefix verified only | none | aguet uses prefixes |
| Accessible default front theme | ✗ (starter views are plain) | all three have their own design | Kami's starter kit, not the core |
| PostgreSQL; MySQL tested | ✗ / ~ (§2.9) | none (MariaDB) | Brief requirement |
| Hooks / events / filters | ~ Eloquent model events, per subclass | — | Extension-points ticket |
| Overridable or disableable core Resources | ✗ (ADR 0009 consequence) | none | |
| Fragment / query cache | ✗ no cache at all; sitemap built per request | none at current volumes | Brief also wants a page cache that expires at the next scheduled publication |
| Scheduler, queue, chunked heavy work | ✗ by design (§2.5) | aguet (contact backstop) | |
| Audit log | ✗ | — | Security principle |
| Security headers / CSP by default | ✗ recipe (§2.7) | aguet | |
| Typed local API without HTTP | ~ query builders, not a formal API | — | |
| Packagist install | ~ VCS repository | — | |

Site needs **outside** the MVP list, which enter the MVP by the brief's rule:

| Feature (comparatif priority) | Sites | Velox |
| --- | --- | --- |
| Simple form with stored submissions, notification and anti-spam (V1) | aguet | site code (Livewire) |
| RSS feeds (V1) | psyao, sea | ✓ 1.1 |
| Media folders (V1) | sea | ✓ 0.17 |
| Video embeds (oEmbed, V1) | psyao, sea | ✓ Video Block (YouTube, Vimeo) |
| Legacy URL handling for former WordPress sites | psyao, sea | ~ (§2.8) |
| Localised custom error pages (V1: 404 pages) | aguet, sea | site code |
| Excerpts / teasers | psyao, sea | ✓ 1.1 (sea kept its own) |
| Focal point / crop-aware images (V1) | sea | ✓ 1.2, unproven |

Velox 1.3 ships front-end search (V1), but no reference site uses it: it stays V1 for Kami.

## 4. Migration path, Velox 1.x → Kami

Kami's data model will differ from Velox's (site key, storage, roles), so every site moves through a data migration, never an in-place upgrade. The three sites are not in the same situation:

| | aguet.dev | psyao.ch | steve-et-aline.ch |
| --- | --- | --- | --- |
| Live system today | Velox 1.1.0, Infomaniak shared hosting | WordPress 6.9.1 (behind Cloudflare) | WordPress (NextGEN) |
| Velox database | production, source of truth | local only | local only |
| Source for Kami | the Velox production database | WordPress, again | WordPress + NextGEN files, again |
| Size | 7 projects, 5 skill groups, 26 tags, 1 home, 1 media item, 1 user | 146 posts, 10 pages, 314 terms, 158 redirects | 10 posts, 5 pages, 14 galleries, 1,105 photos (720 MB), 1,186 redirects |

### 4.1 aguet.dev: zero loss of content and URLs

It is the only site in production, and the move must lose nothing.

**Inventory to preserve** (aguet §6, §8):

- Public URLs: `/`, `/en`, the `/en/` and `/EN` 301s to `/en`, `/sitemap.xml` (two URLs with hreflang), the static `/robots.txt`, `/up`.
- `/cron/{token}`: configured in Infomaniak's task planner. Keep the path, or change the planner in the same deploy.
- The social image's URL `/storage/media/{ulid}/aguet-dev-1200.webp`, already cached by social networks: keep it, or 301 it to the new conversion URL.
- Head tags per Locale (title, description, canonical, hreflang, Open Graph, Twitter card, `og:locale`, Person JSON-LD), already asserted tag by tag in `HomePageTest`.
- Content: Home fields per Locale (translatable and untranslated), Projects with hand order, `featured`, Origin slug and Translations, SkillGroups, Tags in pick order, per-Entry SEO, original dates (A23 shows what goes wrong otherwise).
- The admin account, including its encrypted MFA secret: keep `APP_KEY`.
- `contact_messages`, a site table Kami does not touch.

**Steps:**

1. Capture production as fixtures: a database dump, the media files, and every response above (`curl`, read-only), as was done for the Velox switch-over.
2. In a branch, replace Velox with Kami and rewrite the three Content Types, the views and the contact form against Kami's API (§2.4 needs Steve's decision first).
3. Write one site migration that copies `velox_*` rows into Kami's tables in a transaction ending in a count check, keeping the Velox tables in place (aguet did the same with its pre-Velox tables, A9-A10 show why the migration must use the query builder, not models).
4. Prove it on the production dump: feature tests, the visual suite with `BROWSER_KEEP_DATABASE=1` byte-identical, a replay of the URL inventory.
5. Back up the database **and** the media files (the site's backup page skips media, A18), then deploy through the existing GitHub Actions pipeline in maintenance mode.
6. Drop the Velox tables in a later release, once production has run on Kami.

### 4.2 psyao.ch and steve-et-aline.ch: WordPress → Kami directly

Both sites still run WordPress, whose content may have changed since the local Velox import. Recommendation: port each importer to Kami (with legacy ids and replayable runs, §2.3) and import from a fresh WordPress dump at switch-over time, instead of chaining WordPress → Velox → Kami. The Velox importers remain the reference: their transformations (wpautop copy, video and shortcode reshaping, dead-video list, link rewriting, date handling) carry over, and their URL replay tests (558 and 1,233 former URLs, one 301 to a 200 each) become the acceptance tests.

For sea, the 720 MB of originals cannot be imported inside a web request: import locally or over SSH in batches, then deploy database and files. With on-demand conversions (§2.2), only originals are uploaded; variants appear on first view.

Go-live for both is a hosting switch from WordPress to Kami. Where each WordPress site is hosted today is not in the notes and needs Steve's input before the migration spec.

### 4.3 Order

Proposed for the roadmap: **aguet.dev first** as the thinnest vertical slice (singleton, ordered collections, multilingual, SEO, one media item, one form), then **psyao.ch** (taxonomies, archives, feeds, redirects, code blocks), then **steve-et-aline.ch** (galleries, heavy media, two authors). The roadmap phase confirms or reorders it.

## 5. Infomaniak facts

Facts the design depends on that no documentation gives (`mut` marks them "not documented").

### 5.1 Measured on aguet.dev's host (2026-10-25)

Measured by Steve on the aguet.dev site, PHP 8.5.12: CLI over SSH (`/opt/php8.5/bin/php -m`, `-i`, `-r`, `php artisan db:show`, `which node`) and web through a temporary `phpinfo()` page. Values are those of this site's configuration; another site of the same account may differ.

| Fact | Web (FPM) | CLI | Consequence |
| --- | --- | --- | --- |
| Server API | `FPM/FastCGI`, behind Apache (`X-Forwarded-Proto: https`, real client IP in `REMOTE_ADDR`) | CLI | Under FPM, Symfony's `Response::send()` calls `fastcgi_finish_request()` (`mut` §8): `defer()` work runs after the visitor has the response |
| `disable_functions` | `exec, mail, passthru, pcntl_exec, popen, proc_open, shell_exec, system` | none | Resolves §2.5: `proc_open` is off on the web, on in the CLI. On the web: no `background` queue, no Concurrency `process` driver, no `Schedule::command()`; PHP `mail()` is off too, so mail goes through SMTP. `set_time_limit` is **not** disabled, unlike Infomaniak's documented default |
| `max_execution_time` | **60 s** | 0 (unlimited) | The web scheduler's time budget is under 60 s by default (raisable to 300 s in the Manager, `mut` §9.1). Imports and media batches belong to the CLI |
| `memory_limit` | 640M | 1024M | |
| `upload_max_filesize` / `post_max_size` | 300M / 300M; `max_file_uploads` 100; `max_input_time` 0 | same | Chunked uploads are not needed below 300 MB |
| OPcache | loaded, **disabled** (`opcache.enable` Off) | disabled | Every web request recompiles Laravel and Filament. Large effect on TTFB. `opcache.enable` cannot be switched on from `.user.ini`, and Infomaniak documents ZendOPCache as requiring a Cloud Server ([FAQ 130](https://www.infomaniak.com/fr/support/faq/130/utiliser-zend-guard-loader-ex-zend-optimizer); `PHP_OPcache` is in the Cloud-only Fast Installer, [FAQ 877](https://www.infomaniak.com/en/support/faq/877/install-a-php-appextension-on-a-cloud-server)): on shared hosting, Kami's budgets assume OPcache off |
| Extensions | `intl` (ICU 67.1), `gd`, `imagick` 3.8.1, `exif`, `fileinfo`, `pcntl`, `posix`, `sodium`, `zip`, `bcmath`, `mbstring`, `pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`, `sqlite3`, `mongodb`, `excimer` | same list | Filament's `intl` is there |
| GD formats | WebP yes, **AVIF no** (bundled GD 2.1.0) | same | AVIF needs the Imagick driver: detection per driver is required |
| Imagick | ImageMagick **6.9.11-60 (2021)**, Q16, single-threaded; AVIF, HEIC, WebP listed | same | AVIF encoding through a 2021 ImageMagick 6 is untested: try it before relying on it |
| Database | MariaDB **10.11.19** on a remote host (`okbo.myd.infomaniak.com`), 146 open connections at the time | | Same major as Velox's CI. Every query pays network latency: the "fewer than 15 queries per page" budget matters more |
| SQLite library | 3.34.1 | 3.34.1 | Above Laravel 13's 3.26 floor; no `DROP COLUMN` (3.35) |
| `date.timezone` | `Europe/Zurich` | | Kami must set UTC itself, never rely on the host default (S2) |
| Other | `display_errors` On, `zend.assertions` 1, OpenSSL 1.1.1w, curl 7.74, libxml 2.9.10 (Debian 11 base) | | `kami:doctor` should flag `display_errors` and OPcache off; a fatal error before Laravel boots would be shown to visitors |
| `node` on the SSH `PATH` | | absent | No Node on the server; admin assets ship prebuilt |
| Manager, "PHP / Apache" tab | memory 640 MB, time 60 s (a 60-minute unlock exists), upload 300 MB, `max_input_vars` 10000, Set time limit **on**, `exec`/`proc_open` group **off**, `mail` **off**, `allow_url_fopen` **off**, file compression off, auto-prepend file field | | Matches phpinfo except `allow_url_fopen`, which phpinfo reports On: the toggle and the effective value disagree. Kami must not rely on `allow_url_fopen` (Laravel's HTTP client uses curl). No OPcache control on this tab |
| Response compression (`curl`, read-only) | HTML gzip (33.4 KB → 8.5 KB), `/build/*` gzip with `immutable` caching; no Brotli | | Apache compresses despite the Manager's "file compression" toggle being off |

### 5.2 Still open

| Fact | Why it matters | How to measure |
| --- | --- | --- |
| Does `max_execution_time` keep counting after `fastcgi_finish_request`? | Budget of visit-triggered scheduling | A test route on staging |
| Task planner: HTTP method, timeout, clock alignment | Web scheduler budget and "overdue" logic | A logging endpoint called by the planner for a few runs |
