# Velox's architecture decisions (ADR 0001–0018)

Source: `~/Code/velox` at v1.3.0 (`CHANGELOG.md:5`, 2026-10-25). All paths below are relative to `~/Code/velox`; commit hashes are from its git history.

## Where decisions live

- All 18 ADRs are in `docs/adr/` (no other ADR directory; `CLAUDE.md:9` and `docs/agents/domain.md:9` name `GLOSSARY.md` + `docs/adr/` as the single context). `README.md:50` links them.
- ADR files carry **no dates**. The date given below is that of the commit that created the file (`git log -- docs/adr/<file>`).
- Status: only ADRs 0002, 0003, 0005, 0016 and 0018 have a `status:` front matter, all "accepted" with amendments. The other 13 have no status field. None is marked superseded or deprecated.
- Decisions recorded outside ADRs:
  - `docs/updating.md:33-42` is the post-1.0 policy: deprecation, supported versions, dependency floors, new routes only as opt-in or fallback, new head tags behind an attribute, new members on extension points. It also states "One Site per app … will not support multisite" (`docs/updating.md:41`).
  - `docs/updating.md:18` says minor versions only ship additive migrations.
  - `GLOSSARY.md` holds domain rules phrased as decisions. Access is checked against a Capability, never a Role (`GLOSSARY.md:174`). Accounts are never created by signing up (`GLOSSARY.md:166`). The Trash is never emptied automatically (`GLOSSARY.md:138`). Writers never create or delete Menus (`GLOSSARY.md:102`). A calendar date is never moved into the Display Time Zone (`GLOSSARY.md:16`).
- Renames made after the ADRs were written are not reflected in the ADR text:
  - Tables were prefixed `velox_` in 0.15 (28af109, 2026-10-13; `CHANGELOG.md:307`).
  - `media` became `velox_media_items` and `author_id` became `created_by` (b3c72a2; `CHANGELOG.md:251`).
  - The package became `psyao/velox` (1c6bd85; `CHANGELOG.md:247`).

  ADRs 0002, 0005, 0006 and 0007 still use the old table and column names (`docs/adr/0002-…:7`, `docs/adr/0005-…:7`, `docs/adr/0006-…:3`, `docs/adr/0007-…:3`).

---

## ADR 0001: Velox ships as a Composer package, not an application

- **File:** `docs/adr/0001-composer-package-not-application.md`
- **Status:** none recorded (implicitly accepted).
- **Date:** 2026-10-10 (2436d98).
- **Decision:** Velox is a Composer package installed into an ordinary Laravel app with `composer require` + `velox:install`. Each Site is its own Laravel app holding its own code, and the core is updated with `composer update`, never edited or forked (`:3`).
- **Context/forces:**
  - Velox will run several Sites, so core updates must stay separate from site code. Statamic, Twill and Filament take the same shape (`:3`).
  - A standalone app was rejected because updates would mean merging upstream into each fork (`:7`).
  - A `create-project` skeleton was rejected for the MVP because it is a second repo to keep in step (`:8`).
- **Consequences named:**
  - The repo is the package, developed with an Orchestra Testbench Workbench (`:12`).
  - Core migrations are loaded from the package, not published, and released as semver tags (`:13`).
  - Distribution is a GitHub VCS repo, not Packagist (`:14`).
- **Later evidence:**
  - Holds: migrations are loaded with `loadMigrationsFrom` (`src/VeloxServiceProvider.php:112`). Installation is through a VCS repository (`docs/installation.md:36,42`).
  - The ADR names the package `velox/cms` (`:3`). It was renamed `psyao/velox` in 0.18 (1c6bd85, 2026-10-17; `CHANGELOG.md:247`; `UPGRADING.md:77`), while the namespace stays `Velox\Cms` (`UPGRADING.md:77`). `composer.json:2` reads `psyao/velox`. `CHANGELOG.md:3` still says `velox/cms`.
  - "One Site per app … will not support multisite" is recorded outside the ADR, citing it (`docs/updating.md:41`).

## ADR 0002: All Entries share one table; Fields live in JSON

- **File:** `docs/adr/0002-single-entries-table-with-json-fields.md`
- **Status:** accepted, amended by ADR 0010, 0011 and 0016 (`:2`).
- **Date:** 2026-10-10 (5043298).
- **Decision:**
  - Every Entry of every Content Type is a row in one table, whose core columns are `type`, `title`, `slug`, `status`, `published_at`, `parent_id`, `author_id`, timestamps and `deleted_at`.
  - Scalar Field values sit in a JSON `data` column and SEO values in a JSON `seo` column.
  - Relation Fields go to a pivot (`entry_id`, `field`, `related_type`, `related_id`, `position`).
  - Content Types are PHP classes, so adding a type or Field needs no migration (`:7`).
- **Context/forces:**
  - Routing, taxonomies, menus, preview, scheduling and the sitemap must treat all types uniformly. At a few thousand rows on MariaDB, JSON reads cost nothing measurable (`:9`).
  - A table per type was rejected: slug resolution would hit N tables, and listings and the sitemap would need UNIONs (`:13`).
  - Class-table inheritance was deferred (`:14`). EAV was rejected (`:15`).
- **Consequences named:**
  - Field values have no database constraints; validation runs in PHP (`:19`).
  - Fast filtering would use an indexed MariaDB virtual generated column (`:20`).
  - Field removal or rename has no automatic effect (`:21`).
  - "Scheduled" is not a stored status: a published Entry with a future `published_at` is scheduled, so no cron is needed (`:22`).
- **Later evidence:**
  - The original text dropped undeclared values on save. ADR 0016 records this, and 50091ab (0.22, `CHANGELOG.md:159`) changed it to keep them. Line 21 was rewritten in cd75c16.
  - 677ddce (0.20) made a Field named like a core column fail the boot (`CHANGELOG.md:217`), and lets Fields win over columns added after 1.0 (`CHANGELOG.md:221`). This is now in `:20`.
  - The table is `velox_entries` (`database/migrations/2026_10_10_000000_create_entries_table.php:11`, after 28af109). The ADR's `author_id` is now `created_by` (same file `:23`; b3c72a2).
  - The table also gained `locale`, `origin_id` (ADR 0013), `path` (ADR 0006) and `position` (ADR 0011) (same file `:15,17,28,30`).

## ADR 0003: Content Types declare Fields through a Velox abstraction, not Filament components

- **File:** `docs/adr/0003-velox-field-abstraction-not-filament-components.md`
- **Status:** accepted, amended by ADR 0009 (`:2`).
- **Date:** 2026-10-10 (26af38a).
- **Decision:** The Admin is Filament 5 with Velox as a plugin. Content Type classes never reference Filament: they declare Velox Field classes, which Velox maps to Filament components internally (`:7`).
- **Context/forces:**
  - A Field decides its storage (`data` or the pivot), validation and casting, which a Filament component does not know (`:9`).
  - It also isolates Sites from Filament major-version churn (`:9`).
  - Using Filament components directly was rejected for coupling and because non-Admin consumers would have to read UI definitions (`:13`).
- **Consequences named:**
  - Velox owns one Field class per kind plus its mapping (`:17`).
  - No escape hatch for a raw Filament component in the MVP (`:18`).
- **Later evidence:**
  - The "no escape hatch" consequence is explicitly replaced by ADR 0009 (`docs/adr/0009-…:3`), whose Site forms chain Filament on the generated component (`docs/adr/0009-…:17`).
  - `GLOSSARY.md:38` now says "Velox ships a set; a Site may add its own" Field kinds.

## ADR 0004: Rich text is stored as TipTap JSON, not HTML

- **File:** `docs/adr/0004-rich-text-stored-as-tiptap-json.md`
- **Status:** none recorded.
- **Date:** 2026-10-10 (8c91872).
- **Decision:** The `RichText` Field stores a TipTap/ProseMirror document in `data`, not HTML. HTML is rendered at read time, and `$entry->body` returns an `HtmlString` (`:3`).
- **Context/forces:**
  - Rendering can change (markup, image variants by media id, link rewriting) without migrating content, and a future API can serve structure (`:5`).
  - Schema-driven rendering is the security model: unknown nodes are dropped, and links are limited to `http`, `https`, `mailto`, `tel` and relative URLs, so no purifier is needed (`:5`).
  - HTML (`:9`) and Markdown (`:10`) were rejected.
- **Consequences named:**
  - Every public render goes through JSON-to-HTML (`:14`).
  - Images are nodes referencing media by id (`:15`).
  - SQL/`LIKE` search over bodies is awkward (`:16`).
- **Later evidence:**
  - Line 16 was amended by 5f2109a (1.3.0, 2026-10-25). Site search indexes plain text extracted in PHP into `velox_search` (`src/Search.php:15,40`; `database/migrations/2026_10_25_000016_create_search_table.php:12`; `src/Fields/RichText.php:162`).
  - ADR 0014 builds Blocks on this document.

## ADR 0005: Terms live in their own table, attached to Entries through a plain pivot

- **File:** `docs/adr/0005-terms-in-own-table-not-entries.md`
- **Status:** accepted, amended by ADR 0018 (`:2`).
- **Date:** 2026-10-10 (dad8048).
- **Decision:**
  - Taxonomies are PHP classes. Terms are rows in one `terms` table (`taxonomy`, `name`, `slug`, `parent_id`, `position`, `description`), with slug unique per Taxonomy.
  - Terms attach through a non-polymorphic `entry_term` pivot (`:7`).
- **Context/forces:**
  - A Term is a classifier with no status, date, Trash or Fields (`:9`).
  - Terms-as-Entries was rejected because it would blur Admin and queries (`:13`).
  - A polymorphic `termables` pivot was rejected because it would lose the foreign key for unneeded flexibility (`:14`).
  - A table per Taxonomy was rejected (`:15`).
- **Consequences named:**
  - No custom Fields in the MVP; a JSON `data` column is the path if needed (`:19`).
  - Deleting a Term is permanent; it detaches, and its children move up (`:20`).
  - A Term Archive is its own core route and view (`:21`).
- **Later evidence:**
  - "No Fields" was reversed by 9578e42 (1.3.0; `CHANGELOG.md:10`), recorded in ADR 0018 (`docs/adr/0018-…:20`). The migration adds `data` and `seo` to `velox_terms` (`database/migrations/2026_10_25_000014_add_fields_and_seo_to_terms_table.php:13-14`).
  - Slug uniqueness became per Taxonomy *and Locale* with ADR 0013 (`UPGRADING.md:146`).
  - Deletion matches `:20`: children move up to the parent (`src/Models/Term.php:147-148`). It also adds a rule the ADR doesn't state: a Translation with children refuses deletion (`src/Models/Term.php:140-141`).

## ADR 0006: Each Entry stores its full URL path; old paths redirect to the Entry, not to a URL

- **File:** `docs/adr/0006-stored-full-path-per-entry.md`
- **Status:** none recorded.
- **Date:** 2026-10-10 (646e445).
- **Decision:**
  - Entries and Terms store their full public path in `path`, so resolution is one indexed lookup.
  - Paths are unique site-wide among non-trashed rows, checked in PHP because MariaDB lacks partial indexes.
  - A changed path writes the old one to `redirects`, pointing at the model. Unmatched requests fall back to `redirects` and answer 301, or 404 if the target is not visible (`:3`).
- **Context/forces:**
  - Content Types share prefixes (unprefixed Pages share the root), so prefix+slug resolution would be multi-query and ambiguous (`:5`).
  - Redirects that target the model avoid chains and stale targets (`:5`).
  - Request-time resolution (`:9`) and URL-to-URL redirects (`:10`) were rejected.
- **Consequences named:**
  - A parent's change rewrites its descendants' paths with redirects (`:14`).
  - A prefix change needs `velox:paths` (`:15`).
  - A new path replaces a matching redirect (`:16`).
  - Sites record former paths with `redirectFrom()`, and the 1.1 Redirects page saves through it (`:17`).
- **Later evidence:**
  - Amended by c4da88c (redirects from code) and de81351 (Admin Redirects page, 1.1.0; `CHANGELOG.md:34`). Term redirects came in 57f98a5.
  - The PHP uniqueness check takes row locks (`src/Paths.php:354-356,385`), added after a race (e899ae3, `CHANGELOG.md:209`).
  - `velox:paths` exists (`src/Commands/PathsCommand.php`).
  - `target_type` now stores morph aliases (`src/Paths.php:100,134`; 63063e3, `CHANGELOG.md:245`).

## ADR 0007: Image variants are generated at upload and served as static files

- **File:** `docs/adr/0007-image-variants-generated-at-upload.md`
- **Status:** none recorded.
- **Date:** 2026-10-10 (1858336).
- **Decision:**
  - Media Items are their own table, shared across Entries by id.
  - On upload, Velox generates fixed named-width WebP variants synchronously through Laravel's `Image` facade, writes them to the public disk for direct serving, and keeps the original on a private disk (`:3`).
- **Context/forces:**
  - Shared hosting has no queue worker, and serving should not cost PHP (`:5`).
  - Glide was rejected because it puts PHP on every request (`:9`).
  - Spatie Media Library was rejected because it has one owning model per item (`:10`).
  - Intervention used directly was not preferred, since the facade wraps it (`:11`).
- **Consequences named:**
  - Named widths, no upscaling, and `velox:media:regenerate` to rebuild (`:15`).
  - EXIF is stripped by a custom transformation guarded by a test (`:16`).
  - Uploads over a megapixel limit are refused (`:17`).
  - GIFs and documents get no variants (`:18`).
  - RichText media ids are indexed in the pivot on save, and deleting a Media Item is permanent with a warning (`:19`).
- **Later evidence:**
  - The ADR file has a single commit (1858336).
  - The default variant list in `:15` names `thumb`. It was renamed `xsmall` in 0.18 (3e3c6c2; `CHANGELOG.md:249`; `config/velox.php:85`).
  - The table is `velox_media_items` (b3c72a2; `CHANGELOG.md:251`).
  - Holds in code: `Image::fromBytes(...)->transform(new StripMetadata)->toWebp()` (`src/MediaLibrary.php:287`), `max_megapixels` 40 (`config/velox.php:83`), and `velox:media:regenerate` (`src/Commands/RegenerateMediaCommand.php`).
  - Term and Global media uses are indexed in `velox_media_uses` instead (ADR 0018).

## ADR 0008: Entries become responses through a Content Type hook; Velox stays free of Inertia

- **File:** `docs/adr/0008-entries-render-through-a-content-type-hook.md`
- **Status:** none recorded.
- **Date:** 2026-10-10 (652d7bb).
- **Decision:**
  - A visible Entry becomes a response only through `ContentType::render(Entry $entry)`, which renders the Blade `view()` by default.
  - Inertia Sites override it.
  - Velox ships no Inertia dependency and no default prop serialisation (`:3`).
- **Context/forces:**
  - It is the smallest seam for a fully Site-owned front end (`:5`).
  - Preview and the Home Page reuse the same hook, so `render()` must not assume the Entry is saved or visible (`:5`).
  - Native Inertia support (`:9`) and a config-level renderer (`:10`) were rejected.
- **Consequences named:**
  - 404s never reach `render()` (`:14`).
  - New public surfaces must call `render()` (`:15`).
  - Archives use `renderArchive()` / `archiveView()` (`:16`).
- **Later evidence:**
  - `:16` was added with cfa82fa (Archives).
  - The code matches: `src/ContentType.php:85-95` and `:135`. The docblock repeats the "never assume saved or visible" rule (`src/ContentType.php:86-87`).
  - Related: in 0.24 a Site may no longer declare its own `Route::fallback()` (`CHANGELOG.md:129`).

## ADR 0009: A Site customises the Admin through its own EntryResource subclasses, inside the Velox plugin

- **File:** `docs/adr/0009-site-owned-entry-resource-subclasses.md`
- **Status:** none recorded. It states that it replaces ADR 0003's "no escape hatch" consequence (`:3`).
- **Date:** 2026-10-11 (8d61902).
- **Decision:**
  - A Site may subclass `EntryResource` per Content Type and register it with `VeloxPlugin::make()->entryResources([...])`.
  - The package keeps the base class, pages, Entry actions, scoping and authorisation.
  - The Content Type still never references Filament (`:3`).
- **Context/forces:**
  - The all-in-one plugin was too rigid, and a Site-owned copy would get no updates (`:5`).
  - Rejected options:
    - starter kit (`:9`);
    - publish command (`:10`);
    - extend-in-place hooks (`:11`);
    - a `resource()` method on the Content Type (`:12`).
- **Consequences named:**
  - `EntryForm::*` and `EntriesTable::default*` pieces are public API (`:16`).
  - Velox Fields remain the only storage source, and every value in `data` needs a declared Field (`:17`).
  - A subclass never declares `$model` (`:18`).
  - `velox:make:resource` exists, and nothing is published by default (`:19`).
  - Core Resources (Media, Accounts, Terms, Menus, SEO) can't be swapped yet (`:20`).
- **Later evidence:**
  - The command name was fixed in 14d8042. The code matches: `src/VeloxPlugin.php:46` and `src/Commands/MakeResourceCommand.php:18`.
  - `src/VeloxPlugin.php` exposes no method to swap the core Resources (`:36,46,53,149` are its only public methods).
  - ADR 0015 (`docs/adr/0015-…:18`, 7df705e) later narrowed `:16`. What `parent::form()`, `parent::table()` and the default pieces *return* is outside semver.

## ADR 0010: A Content Type may name its own Entry subclass over the shared table

- **File:** `docs/adr/0010-optional-entry-subclass-per-content-type.md`
- **Status:** none recorded. It amends ADR 0002's "one model" reading (`:3`).
- **Date:** 2026-10-11 (8d61902).
- **Decision:**
  - `ContentType::model()` (default `Entry::class`) may return an `Entry` subclass over the same table, i.e. single-table inheritance.
  - Every query hydrates rows as their type's model, and `Event::query()` is scoped.
  - The subclass's morph class is frozen to `Entry`'s (`:3`).
- **Context/forces:**
  - Sites need methods, named scopes and relations more than real columns (`:5`).
  - Rejected options:
    - a table per type (`:9`);
    - an extension table (`:10`), not needed yet;
    - Site-added columns, which would collide with core migrations (`:11`);
    - the model in plugin config (`:12`).
- **Consequences named:**
  - Every row is `instanceof Entry`. The morph class can't change because pivot, menu and path `target_type` columns store it "without a morph map" (`:16`).
  - Relations are wrappers over `relatedEntries()` (`:17`).
  - Indexed generated columns are still used for fast sorting (`:18`).
  - `EntryResource::getModel()` comes from `model()` (`:19`).
- **Later evidence:**
  - The ADR file has a single commit (8d61902).
  - "Without a morph map" (`:16`) no longer describes the code. 63063e3 (0.18, 2026-10-17) made those columns hold morph aliases (`CHANGELOG.md:245`; `src/VeloxServiceProvider.php:65` registers `velox.entry`, `velox.term`, `velox.media_item`, `velox.global`).
  - The freezing rule itself survives: `Entry::getMorphClass()` returns `Entry`'s alias for every subclass (`src/Models/Entry.php:202-206`). The foreign key is frozen the same way (`src/Models/Entry.php:208-209`).
  - `ContentType::model()` is at `src/ContentType.php:74`.

## ADR 0011: A Content Type may be ordered by hand, through a position column

- **File:** `docs/adr/0011-hand-order-through-a-position-column.md`
- **Status:** none recorded. It amends ADR 0002's column list (`:3`).
- **Date:** 2026-10-12 (0192166).
- **Decision:** `orderable(): bool` (default false) opts a type in. Its Entries carry `entries.position`, the order among siblings: a flat type is one group, and a hierarchical type is ordered level by level (`:3`).
- **Context/forces:**
  - Writers expect to drag rows. Filament reordering needs a real column, and Archives stay indexable (`:5`).
  - A `Number` Field (`:9`), nested sets (`:10`) and a curated relation (`:11`) were rejected.
- **Consequences named:**
  - Only orderable types change. Their Admin list sorts by position (drag, or a Tree page for hierarchical types), and their Archive follows hand order (`:15`).
  - New, copied or moved Entries go last. Trashed Entries keep their position, and an existing type gets an initial newest-first order (`:16`).
  - The Visitor `tree()` drops visible Entries under invisible parents (`:17`).
- **Later evidence:**
  - Flat ordering shipped in f8dc2ac (0.11, `CHANGELOG.md:405`). The hierarchical Tree came in 0.12 (`UPGRADING.md:170`).
  - The column is indexed with `type, parent_id` (`database/migrations/2026_10_10_000000_create_entries_table.php:30,36`). `ContentType::orderable()` is at `src/ContentType.php:64`, and `velox:make:content-type --orderable` exists (`src/Commands/MakeContentTypeCommand.php`).
  - In 0.21 the list of a *non*-orderable type sorts by `updated_at` (`CHANGELOG.md:184`), not newest-first as `:15` reads for Admin lists.

## ADR 0012: Migrations are squashed during 0.x, under an upgrade floor

- **File:** `docs/adr/0012-squash-migrations-in-0x-under-an-upgrade-floor.md`
- **Status:** none recorded.
- **Date:** 2026-10-12 (f47acc7, the first squash). The ADR file's history begins there.
- **Decision:**
  - Package migrations run from `vendor/` and are never published.
  - During 0.x one table's migrations may fold into its oldest file, keeping that file's name, so up-to-date Sites run nothing again.
  - Each squash raises an upgrade floor, is a breaking change, and is noted in `UPGRADING.md`. New columns ship in their own migration first.
  - After 1.0, migrations are squashed only in a major (`:3-11`).
- **Context/forces:**
  - Pre-1.0, with no Site in production, a higher floor costs nothing. Old data migrations call current code that drifts (`:13`).
  - Never squashing (`:17`), new file names (`:18`) and publishing migrations (`:19`) were rejected.
- **Consequences named:**
  - Each squash file reads as the whole table (`:23`).
  - Dependent tables fold into the owning file (`:24`).
  - Skipping the floor isn't caught at `migrate`; the first query fails (`:25`).
- **Later evidence:** Three squashes were applied:
  - f47acc7, floor 0.11 (`CHANGELOG.md:378`; `UPGRADING.md:166`);
  - 1b6605f, floor 0.15 (`CHANGELOG.md:300`; `UPGRADING.md:125`);
  - 28446e1, floor 0.18 (`CHANGELOG.md:233`; `UPGRADING.md:71`).

  Since 1.0 the new migrations are separate additive files (`database/migrations/2026_10_24_000011_*` to `2026_10_25_000016_*`), consistent with `:11` and `docs/updating.md:18,20`. be04b07 documented the "quiet costs" of skipping the 0.18 floor.

## ADR 0013: A Translation is an Entry of its own, one per Locale

- **File:** `docs/adr/0013-a-translation-is-an-entry-per-locale.md`
- **Status:** none recorded.
- **Date:** 2026-10-12 (8bf5ad4).
- **Decision:**
  - Each Translation is a separate Entry row with its own `locale`, linked to its Origin, and its own title, slug, path, status, date, SEO and translatable Fields.
  - Non-translatable Fields live on the Origin only.
  - Monolingual Sites see no change (`:3`).
- **Context/forces:**
  - Everything built on a URL is one value per row (path, slug uniqueness, redirects, Trash, previews, Archives). Per-row Translations inherit it all and allow per-language publication (`:5`).
  - JSON-per-Locale fields (spatie/laravel-translatable, aguet's existing storage) were rejected (`:9`).
  - A table per Locale or a translations table was rejected (`:10`).
- **Consequences named:**
  - Non-translatable values are read from the Origin (`:14`).
  - A Translation's parent is the parent's Translation (`:15`).
  - Classification is on the Origin only (`:17`).
  - A Taxonomy may opt out of translation (`:18`).
  - Menu Item labels/URLs (`:19`) and Media alt text (`:20`) are JSON per Locale, an explicit exception.
  - Importing aguet needs one Entry per Locale (`:21`).
- **Later evidence:**
  - The schema is `locale` + `origin_id`, unique together (`database/migrations/2026_10_10_000000_create_entries_table.php:15,17,38`; `UPGRADING.md:146`).
  - Amendments followed the features: Terms per Locale (b4fca7a), Menus per Locale (2366f06), alt per Locale (2956778).
  - The Taxonomy opt-out was added in 1ba43b2 after cd057c5 (`CHANGELOG.md:147`).
  - ADR 0016 notes that switching a Field to non-translatable still drops Translations' values on save (`docs/adr/0016-…:22`).

## ADR 0014: Blocks live inside RichText, not in a Builder Field

- **File:** `docs/adr/0014-blocks-inside-rich-text-not-a-builder-field.md`
- **Status:** none recorded.
- **Date:** 2026-10-13 (7a845a7).
- **Decision:**
  - Non-text content is a Block, a node inside a `RichText` TipTap document.
  - Sites declare Blocks in code (label, icon, Velox Fields, view), and each `RichText` enables them with `->blocks([...])`. Velox's own Blocks (Video first) are opt-in too.
  - Picked Entries and Media are stored by id in the node.
  - It is built on Filament RichEditor custom blocks, and Velox ships no Content Type for Blocks (`:3`).
- **Context/forces:**
  - psyao.ch and steve-et-aline.ch are classic-editor text with a video or gallery inside, not page layouts. Keeping the content in one place needs no JS build (`:5`).
  - Rejected options:
    - a Builder Field (`:9`), deferred "until a Site needs one";
    - Velox-fixed Blocks (`:10`);
    - shortcodes (`:11`);
    - an Image Block for captions (`:12`). Captions are stored as the image node's `title`, avoiding a JS build.
- **Consequences named:**
  - "Block" means RichText-only (`:16`).
  - Block picks are the source of truth, and Media picked in a Block is indexed in `velox_entry_relations` while Entries are not (`:17`).
  - No `->blocks()` means no Blocks (`:18`).
  - Captioned images are split out into a `<figure>` (`:19`).
- **Later evidence:**
  - Implemented in 2104c53 (Site Blocks, `CHANGELOG.md:282`), 4342a5c (Video block, `CHANGELOG.md:280`) and 7825a64 (captions, `CHANGELOG.md:279`). The ADR was amended in 14bb235 and 6fe4860.
  - `RichText::blocks()` is at `src/Fields/RichText.php:86`. `velox:make:block` exists (`src/Commands/MakeBlockCommand.php`).
  - `Migrate::renameBlock` / `*BlockField` were added under ADR 0016 (`src/Fields/Migrate.php:104-122`).

## ADR 0015: The public surface is what docs/ names; everything else is final and internal

- **File:** `docs/adr/0015-the-public-surface-is-what-docs-names.md`
- **Status:** none recorded.
- **Date:** 2026-10-17 (9bc0144).
- **Decision:**
  - Semver from 1.0 covers what `docs/` names: classes and members, commands, config keys, components and views, route names, and `velox_` columns.
  - Everything else is `@internal`. Every class is `final` except a listed set of extension points.
  - `tests/PublicSurfaceTest.php` snapshots the surface (`:3`).
- **Context/forces:**
  - An inventory found 98 classes, few of them `final` and many public by accident (`:5`).
  - Rejected options:
    - everything public (`:9`);
    - an `@api` tag (`:10`), which would be a second list;
    - no `final` (`:11`), since adding `final` later would break.
- **Consequences named:**
  - New classes are `final` + `@internal` unless documented (`:15`).
  - Filament's inherited statics follow Filament's contract (`:16`).
  - Admin views and translation groups are internal (`:17`).
  - On Admin extension points, semver covers method names and signatures, not what `parent::form()` / `table()` etc. return (`:18`).
- **Later evidence:**
  - `:18` was added in 7df705e, motivated by "three Admin refreshes in 0.21" (`:18`; `CHANGELOG.md:182-186`). 8c7f8fd closed docs gaps before 1.0 (`CHANGELOG.md:238`). Members were moved to `@internal` (`CHANGELOG.md:228`; `UPGRADING.md:73`).
  - In the code, 85 files declare `final class`. The non-final concrete classes in `src` are exactly the extension points listed (`src/Blocks/Video.php`, `src/Models/Entry.php`, `EntryBuilder`, `TermBuilder`, `EntryResource`, `ListEntries`, `CreateEntry`, `EditEntry`), per a grep of `src`.
  - `docs/development.md:36` and `docs/updating.md:18` restate the rule.

## ADR 0016: Field changes are migrated explicitly; unknown values are kept

- **File:** `docs/adr/0016-field-changes-are-migrated-explicitly.md`
- **Status:** accepted, amends ADR 0002, amended by ADR 0018 (`:2`).
- **Date:** 2026-10-18 (cd75c16).
- **Decision:**
  - Velox never rewrites stored values on its own. Undeclared values are kept and ignored on read; a Translation keeps only its translatable Fields.
  - Unreadable values read as `null` and missing ones as defaults (`:7`).
  - Developers move data in ordinary Laravel migrations calling declarative operations, which name types and Blocks by handle or id. The operations rewrite all rows, including Translations and Trash, without saves, events or `updated_at` changes.
  - A read-only check command reports drift and exits non-zero (`:9`).
- **Context/forces:**
  - With name-identified Fields in PHP, no system can tell a rename from remove+add (psyao/velox#284). Wagtail's operations are the closest fit (`:11`).
  - Under ADR 0002, any save (publish, translate, parent move) silently and permanently dropped undeclared values (psyao/velox#283) (`:11`).
  - Tolerant reads only (`:15`), Craft-style resave (`:16`) and stable Field ids (`:17`) were rejected.
- **Consequences named:**
  - Stale JSON persists until migrated, and the check reports it (`:21`).
  - Translatable to non-translatable still drops values. Content Type renames are not covered (`:22`).
  - The operations have no `down()` (`:23`).
- **Later evidence:**
  - Shipped in 0.22: 50091ab (keep unknown values, a breaking change, `CHANGELOG.md:159,165`), 7017da1 (`Migrate` operations, `CHANGELOG.md:163`) and 527604b (`velox:fields:check`, `CHANGELOG.md:164`).
  - `src/Fields/Migrate.php:22-140` now also has Term, Global and Repeater operations (`:56-98,128-140`) beyond those the ADR lists. Term and Global operations came with 9578e42 and e512c06.
  - `velox:fields:check` is at `src/Commands/FieldsCheckCommand.php`.

## ADR 0017: Dates are kept in UTC and shown in a Display Time Zone through a Carbon macro

- **File:** `docs/adr/0017-dates-kept-in-utc-shown-in-a-display-time-zone.md`
- **Status:** none recorded.
- **Date:** 2026-10-23 (c981b4d).
- **Decision:**
  - `app.timezone` and the MySQL/MariaDB connection stay UTC.
  - `velox.display_timezone` (default `null`, meaning no conversion) sets Filament's zone in Velox's service provider boot, so a Site's own `FilamentTimezone::set()` still wins.
  - Views convert with the Carbon macro `inDisplayTimezone()`, never on a `Date` Field (`:3`).
- **Context/forces:**
  - Every Site repeated its zone in its provider and in each view (aguet.dev invented `app.display_timezone`), and a lone date still needs conversion.
  - `tz(null)` is a getter, so a plain `tz(config(...))` cannot replace the macro (`:5`).
  - Rejected options:
    - docs only (`:9`);
    - a getter (`:10`);
    - a Blade component (`:11`);
    - an `Entry` method (`:12`);
    - a cast (`:13`);
    - `VeloxPlugin::boot()` (`:14`).
- **Consequences named:**
  - The macro is global and public (`:18`), and IDE completion needs a helper (`:19`).
  - `Date` Fields shift west of UTC if misused (`:20`).
  - There is one zone per Site (`:21`).
  - Changing `app.timezone` needs data conversion (`:22`).
- **Later evidence:**
  - Implemented exactly in that commit (0.29.0, `CHANGELOG.md:63`): `src/VeloxServiceProvider.php:92-98`, with the code comment citing ADR 0017 (`:92`).
  - No later commits touch the ADR.

## ADR 0018: Fields outside an Entry store their picks as ids, and their Media uses in a morph index

- **File:** `docs/adr/0018-fields-outside-an-entry-store-picks-as-ids.md`
- **Status:** accepted, amends ADR 0005 and ADR 0016 (`:2`).
- **Date:** 2026-10-25 (9578e42).
- **Decision:**
  - Taxonomies declare Fields for Terms (any kind except `RichText`), stored in JSON `data` and `seo` on `velox_terms`.
  - Entry and Media picks are stored as ids in `data`, not in a pivot, because `velox_entry_relations` is keyed by `entry_id`.
  - Media uses are recorded per Field in a morph-keyed `velox_media_uses` on save.
  - `Migrate` gains Term-Field operations (`:7`).
- **Context/forces:**
  - A Term remains a classifier (ADR 0005), and morph-keying the Entry pivot would cost Entries their foreign keys.
  - Global sets (psyao/velox#458) reuse the same storage (`:9`).
  - A Term pivot of its own (`:13`) and Library scans of `data` (`:14`) were rejected.
- **Consequences named:**
  - `holder_id` has no foreign key, so Terms clean up their own uses (`:18`).
  - Deleted picks remain as ids and read as absent (`:19`).
  - ADR 0005's "no Fields" no longer holds, and RichText on Terms is backlog (`:20`).
- **Later evidence:**
  - Same-day implementation: `database/migrations/2026_10_25_000014_add_fields_and_seo_to_terms_table.php:13-18`, `src/Models/Term.php:146` (cleanup on delete) and `src/Fields/Migrate.php:56-74`.
  - Global sets shipped in e512c06 (`CHANGELOG.md:12`) with `velox_globals.data` (`database/migrations/2026_10_25_000015_create_globals_table.php:12,16`), the `velox.global` morph alias (`src/VeloxServiceProvider.php:65`) and `Migrate::*GlobalField` (`src/Fields/Migrate.php:80-98`).
  - No revisions since.

---

## Index

| ADR | Title | Status | Subsystem |
|---|---|---|---|
| 0001 | Composer package, not application | none recorded; package renamed `psyao/velox` (1c6bd85) | Distribution |
| 0002 | Single entries table, Fields in JSON | accepted, amended by 0010, 0011, 0016 | Data model |
| 0003 | Velox Field abstraction over Filament components | accepted, amended by 0009 | Fields / Admin |
| 0004 | Rich text stored as TipTap JSON | none recorded | Rich text |
| 0005 | Terms in their own table | accepted, amended by 0018 | Taxonomies |
| 0006 | Stored full path per Entry; redirects to target | none recorded | Routing / redirects |
| 0007 | Image variants generated at upload | none recorded; `thumb`→`xsmall`, table renamed | Media |
| 0008 | Entries render through a Content Type hook; no Inertia | none recorded | Rendering |
| 0009 | Site-owned EntryResource subclasses | none recorded; replaces 0003's "no escape hatch" | Admin |
| 0010 | Optional Entry subclass per Content Type | none recorded; amends 0002; "no morph map" outdated by 63063e3 | Data model |
| 0011 | Hand order through a position column | none recorded; amends 0002 | Data model / Admin |
| 0012 | Squash migrations in 0.x under an upgrade floor | none recorded; applied 3× (floors 0.11, 0.15, 0.18) | Release / migrations |
| 0013 | A Translation is an Entry per Locale | none recorded | i18n |
| 0014 | Blocks inside RichText, not a Builder Field | none recorded | Rich text |
| 0015 | Public surface is what docs/ names | none recorded | API / semver |
| 0016 | Field changes migrated explicitly; unknown values kept | accepted, amends 0002, amended by 0018 | Fields / migrations |
| 0017 | Dates in UTC, Display Time Zone macro | none recorded | Dates |
| 0018 | Fields outside an Entry store picks as ids | accepted, amends 0005, 0016 | Taxonomies / Globals / Media |
