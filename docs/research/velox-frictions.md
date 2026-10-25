# Velox frictions

Every friction met while building and using Velox (`psyao/velox`, v1.3.0 at the time of writing), compiled 2026-10-25 for Kami. Facts only, no recommendations.

## Sources and conventions

- **Migration logs**: `~/Code/aguet/VELOX-MIGRATION.md` (25 frictions, IDs `A1`…`A25`), `~/Code/psyao/VELOX-MIGRATION.md` (17 frictions, `P1`…`P17`), `~/Code/steve-et-aline/VELOX-MIGRATION.md` (4 frictions, `S1`…`S4`). Friction *n* sits on line 29+*n*, 25+*n* and 23+*n* of each file respectively. Their verdict is quoted as written.
- **GitHub issues** of `psyao/velox`: all 420 issues (`gh issue list -R psyao/velox --state all --limit 500`), IDs `I-<number>`. Many cite the playground log below.
- **Playground log**: `~/Code/velox-test/VELOX-PLAYGROUND.md` (64 frictions, `G1`…`G64`; friction *n* sits on line 817+*n*), the velox-test Site where each Velox feature was tried before release. 24 of its frictions duplicate a row already listed through its issue (or, for G59, through the development docs) and are merged into that row, their IDs in brackets and their own verdicts quoted beside the row's; the other 40 are rows of their own.
- **Developer gotchas**: `~/Code/velox/CLAUDE.md`, `~/Code/velox/docs/development.md` (IDs `D1`…`D11`), with `~/Code/velox/composer.json` and `~/Code/velox/lefthook.yml` where they show the mechanism.
- **Versions**: the first release whose `~/Code/velox/CHANGELOG.md` entry closes the issue, or the first tag containing the commit (`git tag --contains`).
- **Sites**: aguet = aguet.dev (in production on Velox), psyao = psyao.ch, sea = steve-et-aline.ch (both local), playground = velox-test, Velox = Velox's own repository.
- **Origin "audit"** marks a friction found by a code audit or design review rather than met while using a Site.
- **Bucket** (used only for the counts at the end; the verdict column keeps the source's words; a playground "Not needed" or "Watch" counts as `DC` unless its verdict names a third party):
  - `VG` Velox gap (code, including bugs)
  - `VD` Velox docs gap
  - `DC` deliberate choice (by design, accepted with no Velox change, decided kept, not planned, deferred)
  - `SP` site problem (the Site's own code or data, the import's to reshape)
  - `TP` third party or environment (Laravel, Filament, tiptap-php, Testbench, Playwright/Chromium, hosting, DB engine)

---

## 1. Content types and Entries

| ID | Friction | Site(s) | Verdict | Status | Bucket | Sources |
| --- | --- | --- | --- | --- | --- | --- |
| A13 | Every Entry's title input and column were labelled « Titre » with no Content Type hook; aguet's Projects needed « Nom » | aguet | "Velox gap: a Content Type should name its title" | Fixed in Velox 0.25.0 (`titleLabel()`, `Taxonomy::nameLabel()`) | VG | `~/Code/aguet/VELOX-MIGRATION.md:42`, https://github.com/psyao/velox/issues/344, 52903f2 |
| I-117 (G5, G6) | `velox:make:resource` printed a registration line that, pasted into a namespaced panel provider, broke every Admin page with a misleading error | playground (0.9 trial Site) | Bug (issue); G5, G6: "Fixed in Velox #117 (6ddd1a8)" | Fixed in 0.9.1 | VG | https://github.com/psyao/velox/issues/117, 6ddd1a8, `~/Code/velox-test/VELOX-PLAYGROUND.md:822`, `~/Code/velox-test/VELOX-PLAYGROUND.md:823` |
| I-127 (G8, G9) | Duplicate on a Site Resource answered 500 after the copy, and a Site's own `duplicate` action shadowed Velox's | playground | Bug (issue); G8: "Documented in Velox UPGRADING.md 0.10.0 (#127)"; G9: "Fixed in Velox #127 (redirects through `EntryResource::current()`)" | Fixed in 0.10.0 | VG | https://github.com/psyao/velox/issues/127, a6076c1, `~/Code/velox-test/VELOX-PLAYGROUND.md:825`, `~/Code/velox-test/VELOX-PLAYGROUND.md:826` |
| I-129 | No hand order for Entries ("gap (c) of the 0.10 audit"; aguet's Projects use `reorderable('sort_order')`) | aguet, playground | Gap (issue) | Fixed in 0.11.0 (flat) and 0.12.0 (hierarchical, tree) | VG | https://github.com/psyao/velox/issues/129, https://github.com/psyao/velox/issues/133, https://github.com/psyao/velox/issues/134, f8dc2ac, 21c1b83 |
| I-135 (G15) | `velox:make:content-type` had no `--orderable` option and nothing hinted that ordering existed | playground | Gap (issue); G15: "Done in Velox #135 (0.12.0): `--orderable`" | Fixed in 0.12.0 | VG | https://github.com/psyao/velox/issues/135, b3b4388, `~/Code/velox-test/VELOX-PLAYGROUND.md:832` |
| I-391 | The starter Page was generated non-orderable; both WordPress Sites flipped it by hand | psyao, sea | Enhancement (issue) | Fixed in 0.28.0 | VG | https://github.com/psyao/velox/issues/391, b520f73, `~/Code/psyao/VELOX-MIGRATION.md:13`, `~/Code/steve-et-aline/VELOX-MIGRATION.md:12` |
| I-425 | No way to read an Entry's or Term's ancestors for a trail of links; sea's Galleries walked `->parent` one query per level | sea | Enhancement (issue) | Fixed in 0.30.0 | VG | https://github.com/psyao/velox/issues/425, ed80a1b |
| G7 | A Site's own Duplicate action using `replicate()` copies `data` but not `EntryRelation` pivots or Terms | playground | "Done in Velox #118 (0.10.0): Duplicate copies relations and Terms" | Fixed in 0.10.0 | VG | `~/Code/velox-test/VELOX-PLAYGROUND.md:824`, https://github.com/psyao/velox/issues/118 |
| G62 | No `velox:make:global`: a Global set class, its `globals` config entry and its view are written from `docs/globals.md` | playground | "Watch: a scaffold (like `velox:make:taxonomy`) once a second Site declares one" | Open | DC | `~/Code/velox-test/VELOX-PLAYGROUND.md:879` |

## 2. Fields and Blocks

| ID | Friction | Site(s) | Verdict | Status | Bucket | Sources |
| --- | --- | --- | --- | --- | --- | --- |
| S4 | Saving an untouched Entry in the Admin rewrites blank inputs as stored nulls (`"description": null`, `seo` `{title: null, …, noindex: false}`), so a byte-for-byte round trip fails on all 29 Entries | sea | "Not a Velox gap: the values read back identical, which is what Velox promises" | Worked around in the Site's test (compares without nulls) | DC | `~/Code/steve-et-aline/VELOX-MIGRATION.md:27`, `~/Code/steve-et-aline/VELOX-MIGRATION.md:16` |
| I-219 (G30) | A MediaRelation Field holding a pick it no longer accepts blocked the save with no visible error (`ModalTableSelect` lacked nested validation rules) | playground | Bug (issue); G30: "Fixed in Velox #219 (8991ce7): the error shows under the gallery" | Fixed in 0.17.0 | VG | https://github.com/psyao/velox/issues/219, 8991ce7, `~/Code/velox-test/VELOX-PLAYGROUND.md:847` |
| I-211/212 | RichText had no Site-declared Blocks and no video embed: sea's NextGEN shortcodes and psyao's music videos had nowhere to go | psyao, sea | Gap (map "what Velox lacks", https://github.com/psyao/velox/issues/202) | Fixed in 0.17.0 | VG | https://github.com/psyao/velox/issues/212, https://github.com/psyao/velox/issues/211, 2104c53, 4342a5c |
| I-430 (G35, G60) | Block previews logged a `base64_encode(null)` deprecation on every Edit page (Filament's `RichEditorStateCast` encodes a null preview) | playground | Bug; "The bug is Filament's"; G35 (on 0.19): "Not needed: Filament's, a deprecation only, the page renders; seen on PHP 8.5"; G60 (on 1.0.0): "Velox gap (Filament's bug, hit by every Velox Block): override `toPreviewHtml()` in `Block` to return `''`" | Fixed in 1.1.0 (Velox overrides `toPreviewHtml()`) | TP | https://github.com/psyao/velox/issues/430, d444169, `~/Code/velox-test/VELOX-PLAYGROUND.md:852`, `~/Code/velox-test/VELOX-PLAYGROUND.md:877` |
| G31 | `velox:make:block` printed only the class path: nothing named the view it made or said to list the Block in a Field's `->blocks([...])` | playground | "Fixed in Velox: the command names the view and the `->blocks()` line" | Fixed in 0.17.0 | VG | `~/Code/velox-test/VELOX-PLAYGROUND.md:848`, f0b65da |
| G57 | `velox:fields:check` named each Entry by its id only (`Entry 5`) | playground | "Fixed in Velox (d80ce58): `Entry 5 (Fondue night): …`" | Fixed in 0.23.0 | VG | `~/Code/velox-test/VELOX-PLAYGROUND.md:874`, d80ce58 |

## 3. Rich text

| ID | Friction | Site(s) | Verdict | Status | Bucket | Sources |
| --- | --- | --- | --- | --- | --- | --- |
| P1 | No inline code or code block in RichText: imported `<code>` became plain text, `<pre>` lost its line breaks | psyao | "Velox gap" | Fixed in 0.26.0 (`RichText::make()->code()`) | VG | `~/Code/psyao/VELOX-MIGRATION.md:26`, https://github.com/psyao/velox/issues/357, https://github.com/psyao/velox/issues/359, 79ee9e7 |
| P3 | No HTML → RichText conversion; the importer copied RichText's private TipTap extension list | psyao | "Velox gap" | Fixed in 0.26.0 (`RichText::fromHtml()`) | VG | `~/Code/psyao/VELOX-MIGRATION.md:28`, https://github.com/psyao/velox/issues/369, https://github.com/psyao/velox/issues/370, 0a8c794 |
| P4 | tiptap-php's parser keeps bare text inline in list items and quotes, which TipTap's schema refuses | psyao | "tiptap-php's parser, folded into friction 3's" | Fixed in 0.26.0 (`fromHtml()` wraps it) | TP | `~/Code/psyao/VELOX-MIGRATION.md:29` |
| P7 | Flash videos inside centred, sometimes unclosed `<p>`: `fromHtml()` keeps the empty paragraph before the Block | psyao | "Not a Velox gap: HTML's own parsing; the import's to reshape" | Worked around in the importer | SP | `~/Code/psyao/VELOX-MIGRATION.md:32` |
| P9 | `$entry->url()` is absolute (from `APP_URL`), so an internal link rewritten to it would store the dev host | psyao | "By design (a URL is absolute)" | Documented in 0.27.0; importer stores paths | DC | `~/Code/psyao/VELOX-MIGRATION.md:34`, https://github.com/psyao/velox/issues/380, 45645b0 |
| P10 | `fromHtml()` drops an `<img>` without a Media id but keeps its now-empty paragraph | psyao | "Not a Velox gap: the editor keeps an empty paragraph too; the import's to reshape" | Worked around in the importer | SP | `~/Code/psyao/VELOX-MIGRATION.md:35` |
| P17 | Saving in the Admin wrote an absolute thumbnail `src` into each image node, so a body changed on first save and held a stale host | psyao | "Velox gap" | Fixed in 0.27.0 | VG | `~/Code/psyao/VELOX-MIGRATION.md:42`, https://github.com/psyao/velox/issues/379, 604dce3 |
| I-461 | `excerpt()` put a space before punctuation after a link; sea kept its own teaser | sea | Bug (issue) | Fixed in 1.3.0 | VG | https://github.com/psyao/velox/issues/461, 4d3ac1d |
| G29 | The image modal's `caption` would not survive the editor: TipTap's image node keeps only the attributes it declares | playground | "Fixed in Velox #215 before commit (found in review)" | Fixed in 0.17.0 (stored as the declared `title`) | VG | `~/Code/velox-test/VELOX-PLAYGROUND.md:846`, https://github.com/psyao/velox/issues/215, 7825a64 |
| G56 | On 0.21.0, rendering a Post whose RichText body is null logs `base64_encode(): Passing null` from Filament's `RichEditorStateCast` | playground | "Not needed: Filament's code, a deprecation only; watch for a Filament fix" | Open (watched) | TP | `~/Code/velox-test/VELOX-PLAYGROUND.md:873` |

## 4. Media

| ID | Friction | Site(s) | Verdict | Status | Bucket | Sources |
| --- | --- | --- | --- | --- | --- | --- |
| P8 | `MediaLibrary::upload()` takes only an `UploadedFile` and no alt text; the docs did not say how to upload a file from disk | psyao | "Velox docs gap … no new API" | Documented in 0.27.0 | VD | `~/Code/psyao/VELOX-MIGRATION.md:33`, https://github.com/psyao/velox/issues/373, https://github.com/psyao/velox/issues/374, aea654a |
| P11 | Uploads write files inside the import's transaction: a rollback or `migrate:fresh` leaves them on disk (36 KB per psyao run; 1,047 MB per sea run) | psyao, sea | "Not a Velox gap: a database transaction doesn't cover files" | Documented in 0.28.0; sea's importer clears `media/` itself | DC | `~/Code/psyao/VELOX-MIGRATION.md:36`, `~/Code/steve-et-aline/VELOX-MIGRATION.md:10`, `~/Code/steve-et-aline/VELOX-MIGRATION.md:13`, https://github.com/psyao/velox/issues/392, fa92fa8 |
| S1 | The Media Picker's empty state read "Aucun(e) media items" (model class name, untranslated) | sea | "Velox gap" | Fixed in 0.28.0 | VG | `~/Code/steve-et-aline/VELOX-MIGRATION.md:24`, https://github.com/psyao/velox/issues/393, cd3c32b |
| I-217 | No Media Folders to file sea's 1,105 NextGEN photos | sea | Gap (map https://github.com/psyao/velox/issues/202) | Fixed in 0.17.0 | VG | https://github.com/psyao/velox/issues/217, db35f7e |
| I-280/281 | Edit Media's "Used by" listed plain titles; the Library sorted only by upload date | playground (section 25) | Enhancement (issues) | Fixed in 0.21.0 | VG | https://github.com/psyao/velox/issues/280, https://github.com/psyao/velox/issues/281, 49f3a6d, 3c7df92 |
| G52 | A trashed Translation in "Used by" reads `… (copy) (in the Trash) (FR)`: the Trash mark sits inside the Locale code | playground | "Accepted: the label is the "Used in" filter's, shared as #280 asks; reorder in `MediaTable::entryLabel()` if it bothers a Writer" | Unchanged | DC | `~/Code/velox-test/VELOX-PLAYGROUND.md:869` |
| G53 | Sort by still offered Filament's "-" choice, meaning the default (newest first) | playground | "Fixed in Velox: the default choice reads "Newest first" (`defaultSortOptionLabel()`)" | Fixed in 0.21.0 (#281's run) | VG | `~/Code/velox-test/VELOX-PLAYGROUND.md:870` |
| G54 | Switching Sort by from Name descending to File size keeps Descending: the direction belongs to the grid | playground | "Not needed: Filament's control, the direction shows beside it" | Unchanged | DC | `~/Code/velox-test/VELOX-PLAYGROUND.md:871` |
| G55 | In a narrow pane the tile's date wraps under the file size | playground | "Not needed: readable; wider screens keep one line" | Unchanged | DC | `~/Code/velox-test/VELOX-PLAYGROUND.md:872` |
| G64 | `docs/globals.md` said Used by lists a Global set as `Footer`, `Footer (DE)`; it reads `Footer (FR)` for the default Locale too | playground | "Docs: the example should read `Footer (FR)`, `Footer (DE)`, as Entries are labelled" | Fixed on Velox's `main` (a60e385), not in a release yet | VD | `~/Code/velox-test/VELOX-PLAYGROUND.md:881`, a60e385 |

## 5. Routing and URLs

| ID | Friction | Site(s) | Verdict | Status | Bucket | Sources |
| --- | --- | --- | --- | --- | --- | --- |
| A5 | The `{path}` catch-all, registered after boot, shadowed routes added later (aguet's error-page tests); every unknown path queries `velox_entries` | aguet | "Velox gap: register the catch-all with `->fallback()` … the database lookup per unknown path is by design" | Fixed in 0.24.0 (`feat!`) | VG | `~/Code/aguet/VELOX-MIGRATION.md:34`, `~/Code/aguet/VELOX-MIGRATION.md:13`, https://github.com/psyao/velox/issues/337, 3ac9077 |
| A8 | Public pages are served by Velox's `velox.entry` route, so aguet's CSP middleware had no page route to attach to | aguet | "Accepted, no Velox code change: the route names are public surface" | Worked around in the Site (middleware scoped by route name); a Velox middleware group stays in the backlog (open) | DC | `~/Code/aguet/VELOX-MIGRATION.md:37`, https://github.com/psyao/velox/issues/227 |
| P2 | Slugs pass through `Str::slug()`: `sprite-css_avec-spriteme` became `sprite-css-avec-spriteme` | psyao | "By design (Velox paths are lowercase slug segments)" | Documented in 0.28.0 | DC | `~/Code/psyao/VELOX-MIGRATION.md:27`, https://github.com/psyao/velox/issues/382, 31b183e |
| P5 | No own view for the Home Page; `render()` must check `isHomePage()`, which the docs did not show | psyao (then sea) | "Velox docs gap" | Documented in 0.27.0 | VD | `~/Code/psyao/VELOX-MIGRATION.md:30`, https://github.com/psyao/velox/issues/372, 3334310 |
| P13 | An unknown non-canonical path answers Velox's canonical 301 first, then 404 (`/feed/` → `/feed` → 404) | psyao, sea | "Decided: kept … Only the final 404 is promised" | Documented in 0.27.0; open to change "in a later minor" | DC | `~/Code/psyao/VELOX-MIGRATION.md:38`, https://github.com/psyao/velox/issues/375, https://github.com/psyao/velox/issues/378, 1c3e3ea, `~/Code/steve-et-aline/VELOX-MIGRATION.md:15` |
| P14 | Laravel's test client trims a trailing slash, so a replay of former URLs never sends them as they were | psyao | "Laravel's; documented in Velox 0.27.0" | Worked around (raw HTTP kernel request) | TP | `~/Code/psyao/VELOX-MIGRATION.md:39`, https://github.com/psyao/velox/issues/380 |
| P15 | Laravel's stock `.htaccess` redirects a trailing slash before Laravel on Apache: two 301s per former URL (verified on aguet's Infomaniak host) | psyao, aguet | "Velox gap for production" | Fixed in 0.27.0 (`velox:install` removes the rule) | VG | `~/Code/psyao/VELOX-MIGRATION.md:40`, https://github.com/psyao/velox/issues/376, https://github.com/psyao/velox/issues/377, bc972fc, `~/Code/aguet/VELOX-MIGRATION.md:24` |
| I-137/138 (G16) | A tree move or Trash restore refused for a taken path told the Writer to "choose another slug" though no slug changed | playground (section 10) | Defect (issues); G16: "Fixed in Velox #137 (and #138 for a restore): the tree says move it elsewhere or change its slug" | Fixed in 0.12.0 | VG | https://github.com/psyao/velox/issues/137, https://github.com/psyao/velox/issues/138, 2b084f4, 0e2210c, `~/Code/velox-test/VELOX-PLAYGROUND.md:833` |
| I-243 | Path uniqueness checked in PHP only: two simultaneous saves may share a path (audit) | Velox | Fix (issue) | Fixed in 0.21.0 | VG | https://github.com/psyao/velox/issues/243, e899ae3 |

## 6. Multilingual

| ID | Friction | Site(s) | Verdict | Status | Bucket | Sources |
| --- | --- | --- | --- | --- | --- | --- |
| A11 | Each Translation gets its own slug from its title, while aguet's card shows one identifier in both languages | aguet | "Accepted, no Velox change: a slug is per Locale by design (ADR 0013)" | Worked around in the Site (reads the Origin's slug) | DC | `~/Code/aguet/VELOX-MIGRATION.md:40` |
| A14 | The Admin showed raw Filament keys: Filament's French lacks them and `APP_FALLBACK_LOCALE=fr` left no fallback; tests ran in English and missed it | aguet | "Accepted: aguet's own" | Worked around in the Site (`APP_FALLBACK_LOCALE=en`) | SP | `~/Code/aguet/VELOX-MIGRATION.md:43` |
| A15 | On MySQL the Admin kept warning that every type's URLs changed: MySQL reorders JSON keys and `Paths::changed()` compared with `!==` | aguet | "Velox gap" | Fixed in 0.25.0 | VG | `~/Code/aguet/VELOX-MIGRATION.md:44`, https://github.com/psyao/velox/issues/345, 1b49b62 |
| A21 | Right after the production deploy the Admin warned the Locales changed: only `velox:paths` records the Locales fingerprint | aguet | "Velox gap" | Worked around once on the server (`velox:paths`); fixed in 0.26.0 | VG | `~/Code/aguet/VELOX-MIGRATION.md:50`, https://github.com/psyao/velox/issues/347, eca8e34 |
| I-320 | Terms could not stay untranslated; aguet's Tags (`Laravel`, `a11y`) read the same in every language | aguet | Enhancement (decided in https://github.com/psyao/velox/issues/314) | Fixed in 0.23.0 | VG | https://github.com/psyao/velox/issues/320, cd057c5 |
| I-160 (G11, G21, G23-G27) | The multilingual spec's run on the playground (monolingual → fr/de → de removed → back) hit frictions: Trashing a Page with children asked to confirm the Menu link loss before refusing (G11); `velox:paths` said only "0 paths changed" after stamping (G21); Translate headed "Create Page" (G23); every Field translatable by default with no step to review them (G24); the Translations panel stale until reload (G25); a Menu item's Edit and Delete buttons did nothing (G26); removing a Locale still named in a `prefix()` broke the Site at boot (G27) | playground | Fixes (spec comment); G11, G21, G23, G25, G26: "Fixed in Velox" (each with its fix); G24: "Fixed in Velox docs/locales.md"; G27: "Fixed in Velox: docs/locales.md says to take it out of every translated `prefix()`; the boot error says …" | Fixed in 0.14.0 | VG | https://github.com/psyao/velox/issues/160, c5cc23b, a5186a7, 22c6c36, 6b88e40, e7e210a, `~/Code/velox-test/VELOX-PLAYGROUND.md:828`, `~/Code/velox-test/VELOX-PLAYGROUND.md:838`, `~/Code/velox-test/VELOX-PLAYGROUND.md:840`, `~/Code/velox-test/VELOX-PLAYGROUND.md:841`, `~/Code/velox-test/VELOX-PLAYGROUND.md:842`, `~/Code/velox-test/VELOX-PLAYGROUND.md:843`, `~/Code/velox-test/VELOX-PLAYGROUND.md:844`, `~/Code/velox-test/VELOX-PLAYGROUND.md:15` |
| G22 | Translating Posts' prefix before any German Post existed warned "URLs of these Content Types changed", though no URL moved yet | playground | "Not needed: the fingerprint is per Locale, so a German Post would move; running the command is harmless" | Unchanged | DC | `~/Code/velox-test/VELOX-PLAYGROUND.md:839` |
| G58 | `velox:make:taxonomy` scaffolds `single()`, `required()` and `public()` but no `translatable()`: the opt-out is found only in the docs | playground | "Watch: a stub (and an `--untranslated` option) once a second Site needs it" | Open | DC | `~/Code/velox-test/VELOX-PLAYGROUND.md:875`, https://github.com/psyao/velox/issues/320 |

## 7. Menus

| ID | Friction | Site(s) | Verdict | Status | Bucket | Sources |
| --- | --- | --- | --- | --- | --- | --- |
| I-220a (G4) | Menu Item nesting is drag-only: no parent field in the Menu Item form | playground | "Watch" friction: "they wait on real Site use, and any fix is additive"; G4: "Watch on a real Site: fine by hand?" | Open (backlog row) | DC | https://github.com/psyao/velox/issues/220, https://github.com/psyao/velox/issues/227, `~/Code/velox-test/VELOX-PLAYGROUND.md:821` |

The Menu item modal's unbounded preload is listed under Admin (I-286/304).

## 8. Redirects

| ID | Friction | Site(s) | Verdict | Status | Bucket | Sources |
| --- | --- | --- | --- | --- | --- | --- |
| P12 | `/blog/` had two former owners, and `redirectFrom()` targets only an Entry or Term (never an Archive) and refuses a path a Laravel route answers | psyao | "Not a Velox gap: Velox's docs send any other redirect to a Site route" | Worked around with a Site route | SP | `~/Code/psyao/VELOX-MIGRATION.md:37` |
| P16 | The `Redirect` model is `@internal`; the import's count check reads `velox_redirects` directly | psyao | "Not worth an API until a Site needs to list its Redirects" | Open; still holds after 1.1's Redirects page | DC | `~/Code/psyao/VELOX-MIGRATION.md:41`, `~/Code/psyao/VELOX-MIGRATION.md:20`, https://github.com/psyao/velox/issues/227 |
| I-209 | Only Velox wrote Redirects (on path changes); a Site moving from WordPress could not record its former paths | psyao, sea | Gap (map https://github.com/psyao/velox/issues/202) | Fixed in 0.17.0 (`redirectFrom()`) | VG | https://github.com/psyao/velox/issues/209, 5d57da2 |
| I-220b (G32) | `redirectFrom()` refuses `/?p=` permalinks | playground | "Watch" friction; G32: "Watch: check which permalinks steve-et-aline.ch serves before its import; documented in routing.md" | Open (backlog row "WordPress URL shapes, feeds, date archives, `?p=`"); both Sites answer `?p=` with 404 from the Home Page's `render()` | DC | https://github.com/psyao/velox/issues/220, https://github.com/psyao/velox/issues/227, `~/Code/psyao/VELOX-MIGRATION.md:16`, `~/Code/steve-et-aline/VELOX-MIGRATION.md:15`, `~/Code/velox-test/VELOX-PLAYGROUND.md:849` |

## 9. SEO

| ID | Friction | Site(s) | Verdict | Status | Bucket | Sources |
| --- | --- | --- | --- | --- | --- | --- |
| A16 | No public way to seed the SEO settings' Site name (`Setting` is `@internal`) | aguet | "Accepted: the fallback gives the same `og:site_name`" | Worked around (`APP_NAME`); public API open in backlog | DC | `~/Code/aguet/VELOX-MIGRATION.md:45`, https://github.com/psyao/velox/issues/227 |
| A22 | With `public/robots.txt` gone, Infomaniak answers `/robots.txt` itself before Laravel (no `Disallow: /admin`, no sitemap) | aguet | "Accepted: the host's, not Velox's. Velox docs gap" | Worked around (static file restored, psyao/aguet.dev#12); documented in 0.26.0 | TP | `~/Code/aguet/VELOX-MIGRATION.md:51`, https://github.com/psyao/velox/issues/348, bdae135 |
| I-321 | The generated `robots.txt` let search engines crawl the Admin in production | aguet | Enhancement (decided in https://github.com/psyao/velox/issues/317) | Fixed in 0.23.0 | VG | https://github.com/psyao/velox/issues/321, ac3c547 |

## 10. Scheduling, dates and cron

No friction about cron was recorded: Velox registers no scheduled task (`~/Code/velox/docs/hosting.md:18`). The frictions are about stored dates.

| ID | Friction | Site(s) | Verdict | Status | Bucket | Sources |
| --- | --- | --- | --- | --- | --- | --- |
| S2 | MariaDB `TIMESTAMP` columns read in the server's zone (Laravel's connection sets none): a UTC time in the spring-forward hour throws `1292`, and dates shift under another session zone | sea (psyao same default) | "Velox docs gap" | Worked around (`'timezone' => '+00:00'`); documented in 0.28.1 | VD | `~/Code/steve-et-aline/VELOX-MIGRATION.md:25`, https://github.com/psyao/velox/issues/403, 1da2b78 |
| A23 | The content move dated every Entry and Term `now()`: the Admin listed all moved Projects published and edited at the deploy's minute | aguet | "Accepted: aguet's own. Velox docs gap" | Fixed in the Site by a second migration (psyao/aguet.dev#13); documented in 0.26.0 | SP | `~/Code/aguet/VELOX-MIGRATION.md:52`, https://github.com/psyao/velox/issues/350, f4afcf4 |
| I-417 | Each Site repeated its display zone twice (`FilamentTimezone::set()` and `->tz()` in every view); aguet had invented its own `app.display_timezone` | aguet, psyao, sea | Feature (issue) | Fixed in 0.29.0 (`velox.display_timezone`); the follow-up map reports "No Velox gap surfaced" | VG | https://github.com/psyao/velox/issues/417, c981b4d, https://github.com/psyao/velox/issues/420 |
| G3 | The Admin showed times in UTC (Laravel's default `APP_TIMEZONE`); a Swiss Site wants `Europe/Zurich` | playground | "Documented in Velox docs/installation.md" | Documented in 0.9.2 (985bc58) as setting `config/app.php`; the same page now says to keep UTC and set `velox.display_timezone` (I-417) | VD | `~/Code/velox-test/VELOX-PLAYGROUND.md:820`, 985bc58, `~/Code/velox/docs/installation.md:70` |

## 11. Cache

| ID | Friction | Site(s) | Verdict | Status | Bucket | Sources |
| --- | --- | --- | --- | --- | --- | --- |
| A12 | `WithoutModelEvents` in `DatabaseSeeder` muted the `Setting` events that refresh Velox's per-request Settings: the visual suite answered 404 on `/` | aguet | "Velox gap" | Fixed in 0.25.0 | VG | `~/Code/aguet/VELOX-MIGRATION.md:41`, https://github.com/psyao/velox/issues/343, 36014df |
| I-125 | Preview tokens piled up in the session and would overflow a cookie session after ~30-40 previews (audit) | Velox | Gap (issue) | Fixed in 0.10.0 (5 most recent kept) | VG | https://github.com/psyao/velox/issues/125, cc39dc6 |

## 12. Install and deploy

| ID | Friction | Site(s) | Verdict | Status | Bucket | Sources |
| --- | --- | --- | --- | --- | --- | --- |
| A3 | `velox:install` rewrote `bootstrap/providers.php` (dropping `use` imports) even though the provider was listed | aguet | "Velox gap" | Fixed in 0.24.0 | VG | `~/Code/aguet/VELOX-MIGRATION.md:32`, https://github.com/psyao/velox/issues/338, 41d7b37 |
| A4 | The user model's own `canAccessPanel()` (returning `true`) won over `IsAccount`'s: the Admin stayed open to every user | aguet | "Velox gap, security-relevant" | Fixed in 0.24.0 | VG | `~/Code/aguet/VELOX-MIGRATION.md:33`, https://github.com/psyao/velox/issues/336, 90786a7 |
| A6 | `role` is not mass assignable: the seeder silently dropped it and factory users had none, so Admin tests were refused | aguet | "Accepted; Velox docs note" | Documented in 0.24.0 | VD | `~/Code/aguet/VELOX-MIGRATION.md:35`, https://github.com/psyao/velox/issues/339, 396de80 |
| A7 | Re-running `velox:install` wrote back every starter file a Site had removed | aguet | "Velox gap" | Fixed in 0.25.0; re-run on aguet writes nothing | VG | `~/Code/aguet/VELOX-MIGRATION.md:36`, `~/Code/aguet/VELOX-MIGRATION.md:22`, https://github.com/psyao/velox/issues/341, 3695fe6 |
| A9 | A past aguet migration used the `SiteContent` model, so removing the model broke `migrate` on a fresh database | aguet | "Accepted: aguet's own" | Fixed in the Site (query builder) | SP | `~/Code/aguet/VELOX-MIGRATION.md:38` |
| A10 | Past aguet migrations ran a seeder and called the `Tag` model, breaking `migrate` on a fresh database | aguet | "Accepted: aguet's own" | Fixed in the Site | SP | `~/Code/aguet/VELOX-MIGRATION.md:39` |
| A24 | Velox's Infomaniak deploy page stored the private repo's Composer token on a host shared by every site of the account, and skipped `velox:fields:check` | aguet | "Velox docs gap" | Worked around (`COMPOSER_AUTH` on stdin, aguet b312063); documented in 0.26.0 | VD | `~/Code/aguet/VELOX-MIGRATION.md:53`, https://github.com/psyao/velox/issues/349, 30d05d4 |
| S3 | A fresh `velox:install` left three files failing Pint (`routes/web.php`, user model trait order, `bootstrap/providers.php`) | sea | "Velox gap" | Fixed in 0.28.1; the Site formatted them itself (b312a10) | VG | `~/Code/steve-et-aline/VELOX-MIGRATION.md:26`, https://github.com/psyao/velox/issues/414, 1617a3b |
| I-120 (G1) | `velox:install` crashed at the end when run non-interactively (Developer prompt), and takes no `--email`/`--name`/`--password` to be scripted | playground (0.9 trial Site) | Defect (issue); G1: "Crash fixed in Velox #120 (0.9.2); `--email`/`--name` options only once a real provisioning script needs them" | Crash fixed in 0.9.2; options not added | VG | https://github.com/psyao/velox/issues/120, d25ca6e, `~/Code/velox-test/VELOX-PLAYGROUND.md:818` |
| I-194 | Unprefixed tables (`media`, `settings`, `menu_items`) would clash with common packages (anticipated) | Velox | Gap (issue) | Fixed in 0.15.0 (`velox_` prefix) | VG | https://github.com/psyao/velox/issues/194, 28af109 |
| G2 | `boost:update` doesn't add Velox's skill unless `boost:install` opted the package in; Velox's docs never mentioned Boost | playground | "Documented in Velox docs/installation.md" | Documented in 0.9.2 | VD | `~/Code/velox-test/VELOX-PLAYGROUND.md:819`, 985bc58, `~/Code/velox/docs/installation.md:89` |
| G33 | `composer require psyao/velox` stopped at `package:discover` (`Class "Velox\Cms\Fields\Video" not found`): UPGRADING.md 0.18.0 gave the Composer commands before the renames | playground | "Fixed in Velox UPGRADING.md (c787621): rename first" | Documented in 0.18.0 | VD | `~/Code/velox-test/VELOX-PLAYGROUND.md:850`, c787621 |
| G34 | UPGRADING.md said a Site with a published `config/velox.php` keeps its variant names; a config with no `media` key got `xsmall` and the Admin asked for a regenerate | playground | "Fixed in Velox UPGRADING.md (c787621): a Site listing no variants regenerates" | Documented in 0.18.0 | VD | `~/Code/velox-test/VELOX-PLAYGROUND.md:851`, c787621 |
| G61 | `composer require psyao/velox:^1.2` reports an advisory on livewire/livewire 4.4.7 (GHSA-mhf7-xp6r-6qrc), pulled by Filament 5.10.2 | playground | "Not Velox's: a Site dependency; Velox's lowest-deps CI resolves its own" | Worked around in the Site (`composer update livewire/livewire`); still open on the 1.3.0 run | TP | `~/Code/velox-test/VELOX-PLAYGROUND.md:878`, `~/Code/velox-test/VELOX-PLAYGROUND.md:29` |
| G63 | Turning on `search.route` needs `resources/views/velox/search.blade.php`, which `velox:install` didn't publish | playground | "Velox gap: publish a starter search view (and a commented form in the layout) with `velox:install`, kept when the Site edits it" | Fixed on Velox's `main` (a60e385), not in a release yet | VG | `~/Code/velox-test/VELOX-PLAYGROUND.md:880`, a60e385 |

## 13. Admin and Filament

| ID | Friction | Site(s) | Verdict | Status | Bucket | Sources |
| --- | --- | --- | --- | --- | --- | --- |
| I-100/104 | After the 0.8 MVP, the Filament-plugin packaging "feels too rigid, enough to block adoption even by its author": no per-type Filament form/table, no Site Resources beside Velox's, no per-type model (anticipated scenarios, aguet as evidence) | aguet, Velox | Map decision | Fixed in 0.9.0 (Site-owned Entry models and Resources) | VG | https://github.com/psyao/velox/issues/100, https://github.com/psyao/velox/issues/104, https://github.com/psyao/velox/issues/109, d943ed1, 089e93a |
| I-128 (G10) | "Delete permanently" confirmations headed "Force delete" (Filament default) | playground | Defect (issue); G10: "Fixed in Velox #128 (headings say "Delete permanently")" | Fixed in 0.10.0 | VG | https://github.com/psyao/velox/issues/128, 134c1af, `~/Code/velox-test/VELOX-PLAYGROUND.md:827` |
| I-130 (G12, G14) | `discoverResources()` registered Site EntryResource subclasses twice (500 on every page); gating a Site Resource undocumented (a Writer could edit Contact Messages) | playground | Defect (issue); G12: "Fixed in Velox #130 (`EntryResource::$isDiscovered = false`)"; G14: "Documented in Velox docs/accounts.md (#130)" | Fixed in 0.11.0 | VG | https://github.com/psyao/velox/issues/130, 423181f, `~/Code/velox-test/VELOX-PLAYGROUND.md:829`, `~/Code/velox-test/VELOX-PLAYGROUND.md:831` |
| I-131 (G13) | Global search did not find Entries or Terms | playground (section 8) | Gap (issue); G13: "Fixed in Velox #131: Entries and Terms searchable" | Fixed in 0.11.0 | VG | https://github.com/psyao/velox/issues/131, 0ae8446, `~/Code/velox-test/VELOX-PLAYGROUND.md:830` |
| I-141 | Accounts could only be added by mailed Invitation: blocked on a host without mail | Velox | Gap (issue) | Fixed in 0.13.0 | VG | https://github.com/psyao/velox/issues/141, 4ac0d08 |
| I-195 | Laravel's validation messages stay English in a French Admin | Velox | Docs (decided in https://github.com/psyao/velox/issues/192) | Documented in 0.15.0 (`laravel-lang/lang`, Site side) | VD | https://github.com/psyao/velox/issues/195, 4729fbf |
| I-255 | Playground audit on 0.20: body squeezed to half width, five same-weight header buttons, no status in header, Save only at bottom, overflowing row actions, Locale switcher styled as primary, 12 flat nav items, red ✗ for "MFA off" | playground | Map → spec https://github.com/psyao/velox/issues/269 | Fixed in 0.21.0 (#270-#279) | VG | https://github.com/psyao/velox/issues/255, 0dd63ff, 655cf87, a6429bc |
| I-268 | Tree pages (`solution-forest/filament-tree`): 20 coloured buttons on 10 rows, order lost unless Save clicked, low density, unclear hierarchy, no search (T1-T6, U1-U9) | playground | Grilling resolution | Fixed in 0.21.0 | VG | https://github.com/psyao/velox/issues/268, https://github.com/psyao/velox/issues/279, f87187a |
| I-286/304 | Audit: 153 of 971 tests failed under `shouldBeStrict()` (381 lazy loads, all Velox code); the EntryRelation picker, Taxonomy input and Menu item modal preloaded every candidate | Velox | Research + enhancements | Fixed in 0.22.0 | VG | https://github.com/psyao/velox/issues/286, https://github.com/psyao/velox/issues/299, https://github.com/psyao/velox/issues/304, https://github.com/psyao/velox/issues/306, https://github.com/psyao/velox/issues/307, c84a2c5, 0ad1cc1, 06bd9e5, 6523f60 |
| G17 | The Create and Set password modals submitted with "Submit", and Create's modal was headed just "Create" | playground | "Fixed in Velox: "Create an Account" submits with "Create", Set password with "Set password"" | Fixed in 0.13.0 | VG | `~/Code/velox-test/VELOX-PLAYGROUND.md:834`, ec56a12, https://github.com/psyao/velox/issues/141 |
| G18 | **Generate a password** gave symbols, hard to dictate or type when handed over | playground | "Fixed in Velox: letters and digits only" | Fixed in 0.13.0 | VG | `~/Code/velox-test/VELOX-PLAYGROUND.md:835`, ec56a12 |
| G19 | The dead Invitation link of an Account made Active by Set password still said "Ask for a new Invitation" | playground | "Fixed in Velox: an Active Account's dead link says to sign in with its password" | Fixed in 0.13.0 | VG | `~/Code/velox-test/VELOX-PLAYGROUND.md:836`, ec56a12 |
| G38 | The Edit Entry ⋮ More trigger was orange (primary), where the spec wants it grey | playground | "Fixed in Velox: `->color('gray')` on the Edit and Create Entry groups; admin-ux.md says so (ListMedia's ⋮ is still orange: its own slice)" | Fixed in 0.21.0 (#273's run) | VG | `~/Code/velox-test/VELOX-PLAYGROUND.md:855`, https://github.com/psyao/velox/issues/273 |
| G39 | The Edit Entry subheading shows seconds ("Published on Oct 12, 2026 11:16:25") | playground | "Not needed: same format as the sidebar's publication date; a Site sets `defaultDateTimeDisplayFormat()`" | Unchanged | DC | `~/Code/velox-test/VELOX-PLAYGROUND.md:856` |
| G40 | In ⋮, Schedule, Reschedule and Revert to Draft had no icon while the other items did | playground | "Fixed in Velox: calendar icon on Schedule and Reschedule, U-turn arrow on Revert to Draft" | Fixed in 0.21.0 (#273's run) | VG | `~/Code/velox-test/VELOX-PLAYGROUND.md:857` |
| G42 | Delete Folder's confirmation submitted with "Confirm", where every other Delete submits with "Delete" | playground | "Fixed in Velox #277: "Delete"" | Fixed in 0.21.0 | VG | `~/Code/velox-test/VELOX-PLAYGROUND.md:859`, https://github.com/psyao/velox/issues/277 |
| G43 | New Folder, Rename Folder and Move to… submitted with Filament's "Submit" | playground | "Fixed in Velox: Create, Rename, Move" | Fixed in 0.21.0 (#277's run) | VG | `~/Code/velox-test/VELOX-PLAYGROUND.md:860` |
| G44 | The grey header button read "Create" beside "Invite", not saying what differs | playground | "Fixed in Velox #278: "Create with password"" | Fixed in 0.21.0 | VG | `~/Code/velox-test/VELOX-PLAYGROUND.md:861`, https://github.com/psyao/velox/issues/278 |
| G45 | Cancel Invitation's confirmation offered "Cancel" and "Confirm"; Disable MFA submitted with "Confirm" | playground | "Fixed in Velox #278: "Keep Invitation" / "Cancel Invitation"; "Disable MFA"" | Fixed in 0.21.0 | VG | `~/Code/velox-test/VELOX-PLAYGROUND.md:862`, https://github.com/psyao/velox/issues/278 |
| G46 | The rows' ⋮ triggers on the Categories and Menus trees were orange (primary) | playground | "Fixed in Velox #279: `->color('gray')` on both tree groups" | Fixed in 0.21.0 | VG | `~/Code/velox-test/VELOX-PLAYGROUND.md:863`, https://github.com/psyao/velox/issues/279 |
| G47 | Delete in a Categories tree row's ⋮ did nothing (the tree package resolves actions only with its own `tree` context, as G26 on Menus) | playground | "Fixed in Velox #279: TermTree shares MenuTree's fix (`MountsTreeActions`)" | Fixed in 0.21.0 | VG | `~/Code/velox-test/VELOX-PLAYGROUND.md:864`, https://github.com/psyao/velox/issues/279 |
| G48 | The Menu rows' pencil button was blank: Filament gives `EditAction` its icon only in a table | playground | "Fixed in Velox #279: `->icon(Heroicon::PencilSquare)`" | Fixed in 0.21.0 | VG | `~/Code/velox-test/VELOX-PLAYGROUND.md:865`, https://github.com/psyao/velox/issues/279 |
| G49 | A Site Resource joining Settings without a sort landed between SEO and Accounts instead of first | playground | "Fixed in Velox #270: SEO and Accounts sort 0 and 1, as Content's items from 0 up" | Fixed in 0.21.0 | VG | `~/Code/velox-test/VELOX-PLAYGROUND.md:866`, https://github.com/psyao/velox/issues/270 |
| G50 | In the Locale switcher's dropdown the unchecked Locales sit flush left while the checked one is indented by its check | playground | "Accepted: Filament's dropdown gives an item without icon no gutter; a blank icon would be a hack" | Unchanged | DC | `~/Code/velox-test/VELOX-PLAYGROUND.md:867` |

## 14. Testing and tooling

| ID | Friction | Site(s) | Verdict | Status | Bucket | Sources |
| --- | --- | --- | --- | --- | --- | --- |
| A1 | The visual suite failed 9 of 13 snapshots: baselines from macOS 15.7.5/Chromium v1223-1228, machine now macOS 26.6/Chromium v1243 | aguet | "Closed in step 2: baselines regenerated" | Fixed in the Site | TP | `~/Code/aguet/VELOX-MIGRATION.md:30` |
| A2 | Playwright 1.64 needs Chromium v1248, not installed, which re-renders snapshots | aguet | "Closed in step 2" | Fixed in the Site | TP | `~/Code/aguet/VELOX-MIGRATION.md:31` |
| A20 | The first French desktop capture sometimes fails Pest's byte comparison (12 pixels, one shade off), then passes | aguet | "Closed in step 15 … a likely cause is Chromium's anti-aliasing under load, against Pest's byte comparison" | Worked around (`composer test:visual` reruns a failed suite once) | TP | `~/Code/aguet/VELOX-MIGRATION.md:49`, `~/Code/aguet/VELOX-MIGRATION.md:23` |
| I-74/75 | Killed runs orphaned Playwright servers; Pest hung after its summary (`pest-plugin-browser` v4 stop signal) | Velox | Bug (issues) | Fixed in 0.5.0 (Pest 5) | TP | https://github.com/psyao/velox/issues/74, https://github.com/psyao/velox/issues/75, 7132236, 3c97312 |
| I-193 | `league/uri-components` 7.0-7.2 crashed Filament's profile page at lowest versions | Velox | Fix | Floor added in 0.2.0, turned into a `conflict` in 0.15.0 | TP | https://github.com/psyao/velox/issues/193, eb03019, 0ffc2f8 |
| I-198 | Four tests failed at `--prefer-lowest`: Filament < 5.9 dropped array state in `fillPartially()`; old deps printed deprecations into the Install subprocess's JSON | Velox | Bug; "a real bug, not a test artefact" | Fixed in 0.15.0 (Filament `^5.9`) | TP | https://github.com/psyao/velox/issues/198, a300cf8, 634c676 |
| I-285/293 | Pre-push took ~72 s: 13 s of sign-in timebox sleeps, `route:cache` tests writing into Testbench's shared skeleton, Install tests unsafe in parallel, paratest dropping `-d zend.assertions=-1`, a serial run deleting Testbench's `503.blade.php` | Velox | Research findings | Fixed in 0.22.0 (two passes, ~18 s) | TP | https://github.com/psyao/velox/issues/285, https://github.com/psyao/velox/issues/293, ba759cb |
| I-302 | Cached config and routes in tests measured, no clear gain | Velox | Closed not planned | Not adopted | DC | https://github.com/psyao/velox/issues/302 |
| I-305 | CI matrix ~3 min | Velox | Closed not planned ("leave the matrix as it is") | Unchanged then | DC | https://github.com/psyao/velox/issues/305 |
| I-346 | CI ran SQLite only, so MySQL's JSON key reordering (A15) slipped through | aguet | Enhancement | MariaDB 10.11 and MySQL 8.4 jobs added in 0.25.0; reduced to MariaDB only in 0.28.1; docs now say "MySQL is not tested" | VG | https://github.com/psyao/velox/issues/346, 4f6f662, d98526d, `~/Code/velox/docs/development.md:40` |
| I-404/405 | The account reached 90% of its 3,000 Actions minutes; checks stopped on push to `main` | Velox | Map decision | Checks on pull requests only since f5a495c | DC | https://github.com/psyao/velox/issues/404, https://github.com/psyao/velox/issues/405, f5a495c |
| D1 | Running `vendor/bin/phpstan` alone lets Larastan rebuild Testbench's package manifest without Velox; `composer analyse` re-runs `package:discover` | Velox | Gotcha | Worked around in the script | TP | `~/Code/velox/CLAUDE.md:21`, `~/Code/velox/composer.json:84` |
| D2 | The `Install` testsuite must run serially, after the parallel pass: it publishes into Testbench's shared skeleton | Velox | Gotcha | Worked around in `composer test` | TP | `~/Code/velox/CLAUDE.md:21`, `~/Code/velox/composer.json:90`, `~/Code/velox/docs/development.md:66` |
| D3 | CI runs on pull requests only, so the Lefthook hooks are all that checks `main`; never `--no-verify` | Velox | Gotcha | Standing rule | DC | `~/Code/velox/CLAUDE.md:21`, `~/Code/velox/lefthook.yml:16` |
| D4 | release-please does not run on push, and the release PR's checks need a manual retrigger | Velox | Gotcha | Standing rule | DC | `~/Code/velox/CLAUDE.md:29`, `~/Code/velox/docs/development.md:60` |
| D5 | Tests run with `zend.assertions=-1`: an assignment inside `assert()` never runs | Velox | Gotcha | Standing rule | DC | `~/Code/velox/docs/development.md:34` |
| D6 | A deliberate public-surface change needs `PublicSurfaceTest --update-snapshots`; a new class stays out with `@internal` | Velox | Gotcha | Standing rule | DC | `~/Code/velox/docs/development.md:36` |
| D7 (G59) | The suite runs on SQLite but behaviour differs on server engines (JSON key order, row locks); a local server run must be serial; `--parallel` leaves per-worker databases, dropped by an escaped `LIKE` pattern. G59: a cleanup by `velox%` dropped the playground's `velox_test` database | Velox, playground | Gotcha; G59: "Fixed in Velox (d0b2377): `docs/development.md` runs the suite on `velox_suite`, a name no Site uses, and drops only its workers' databases …" | Documented in 0.25.0 | TP | `~/Code/velox/docs/development.md:40`, `~/Code/velox/docs/development.md:47`, `~/Code/velox-test/VELOX-PLAYGROUND.md:876`, d0b2377 |
| D8 | A JSON value read back compares with `toBeStored()`, not `toBe()`: MySQL reorders keys | Velox | Gotcha | Documented | TP | `~/Code/velox/docs/development.md:54` |
| D9 | A test needing a Site route at `/`, `/robots.txt` or `/sitemap.xml` must add it in the test; Preview tests read the `xjs` effect, Share preview tests `mountedActions` | Velox | Gotcha | Documented | TP | `~/Code/velox/docs/development.md:68` |
| D10 | The Admin's one third-party script (smartcrop) is committed, since Sites install without Node | Velox | Gotcha | Documented | DC | `~/Code/velox/docs/development.md:16` |
| D11 | Testbench skeleton pollution (fix: reinstall testbench-core and `composer dump-autoload`); judge a run by its `Tests:` line after Playwright hangs | Velox | Gotcha (map note) | Recorded in agent memory | TP | https://github.com/psyao/velox/issues/282 |
| G20 | Menu drags can't be automated through DevTools | playground | "Not needed" | Worked around (`Livewire.find(…).call('updateTree', [...])`) | DC | `~/Code/velox-test/VELOX-PLAYGROUND.md:837` |
| G28 | `mysqldump` on the PATH can't load `mysql_native_password` against Herd's MariaDB | playground | "Not needed: section 14 names `mariadb-dump`" | Worked around (Herd's `mariadb-dump`) | DC | `~/Code/velox-test/VELOX-PLAYGROUND.md:845` |
| G36 | Checking for false unsaved-changes alerts needs `unsavedChangesAlerts()` on: Filament leaves `savedDataHash` unset otherwise (result on 0.20: no false alert) | playground | "Not needed" | Worked around for the check, then reverted | DC | `~/Code/velox-test/VELOX-PLAYGROUND.md:853`, https://github.com/psyao/velox/issues/262 |
| G37 | `mysqldump` on the PATH can't take the backup and leaves a 440-byte error file named like a dump | playground | "Not needed: the machine's client, not Velox" | Worked around (Herd's `mariadb-dump`) | TP | `~/Code/velox-test/VELOX-PLAYGROUND.md:854` |
| G41 | The backup step's system `mysqldump` fails on `mysql_native_password` | playground | "Not Velox: the runbook could name Herd's binary" | Worked around (Herd's `mariadb-dump`) | TP | `~/Code/velox-test/VELOX-PLAYGROUND.md:858` |
| G51 | The Browser pane shows no "Leave site?" dialog on a dirty form, though the alert fires (`defaultPrevented`) | playground | "Not Velox: the test browser. Worth one look in a real browser" | Worked around (dispatching `beforeunload` from the console) | TP | `~/Code/velox-test/VELOX-PLAYGROUND.md:868` |

## 15. Other (backups, import data, local databases)

| ID | Friction | Site(s) | Verdict | Status | Bucket | Sources |
| --- | --- | --- | --- | --- | --- | --- |
| A17 | A pre-move dump drops and recreates `users` without Velox's `role` and MFA columns, so a restore could not keep the admin signing in | aguet | "Accepted: aguet's own" | Fixed in the Site (`DatabaseRestorer` drops all, imports, migrates, re-asserts the admin) | SP | `~/Code/aguet/VELOX-MIGRATION.md:46` |
| A18 | The backup page's dump holds every table but no Media files | aguet | "Accepted, as the map decided: backup stays Site code, no Velox ticket" | Open (no workaround) | SP | `~/Code/aguet/VELOX-MIGRATION.md:47` |
| A19 | The dump set no client charset: imported with the `mysql` CLI, accents were double-encoded | aguet | "Accepted: aguet's own" | Fixed in the Site (`SET NAMES utf8mb4`) | SP | `~/Code/aguet/VELOX-MIGRATION.md:48` |
| A25 | `migrate` on the local database refuses the content move: seeders already put Velox content there | aguet | "Accepted: aguet's own, local only" | Worked around (`migrate:fresh --seed`) | SP | `~/Code/aguet/VELOX-MIGRATION.md:54` |
| P6 | The dead-video list behind the spec's count was never committed, and the count was 98 where 99 Video Blocks are right | psyao | "Not a Velox gap" | Rebuilt by the Site | SP | `~/Code/psyao/VELOX-MIGRATION.md:31` |

---

## Counts

Per subsystem and bucket (one row per table entry above; grouped issues count once).

| Subsystem | VG | VD | DC | SP | TP | Total |
| --- | --- | --- | --- | --- | --- | --- |
| 1. Content types and Entries | 8 | 0 | 1 | 0 | 0 | 9 |
| 2. Fields and Blocks | 4 | 0 | 1 | 0 | 1 | 6 |
| 3. Rich text | 5 | 0 | 1 | 2 | 2 | 10 |
| 4. Media | 4 | 2 | 4 | 0 | 0 | 10 |
| 5. Routing and URLs | 4 | 1 | 3 | 0 | 1 | 9 |
| 6. Multilingual | 4 | 0 | 3 | 1 | 0 | 8 |
| 7. Menus | 0 | 0 | 1 | 0 | 0 | 1 |
| 8. Redirects | 1 | 0 | 2 | 1 | 0 | 4 |
| 9. SEO | 1 | 0 | 1 | 0 | 1 | 3 |
| 10. Scheduling and dates | 1 | 2 | 0 | 1 | 0 | 4 |
| 11. Cache | 2 | 0 | 0 | 0 | 0 | 2 |
| 12. Install and deploy | 7 | 5 | 0 | 2 | 1 | 15 |
| 13. Admin and Filament | 21 | 1 | 2 | 0 | 0 | 24 |
| 14. Testing and tooling | 1 | 0 | 11 | 0 | 16 | 28 |
| 15. Other | 0 | 0 | 0 | 5 | 0 | 5 |
| **Total** | **63** | **11** | **30** | **12** | **22** | **138** |

Per source: the three migration logs give 46 entries (aguet 25, psyao 17, sea 4); the playground log gives 40 entries of its own, its other 24 frictions merged into existing rows; issues alone give 41 entries (some grouping several issues); developer docs give 11. Status: every friction is fixed, documented, worked around or left unchanged by its verdict, except these still open: in Velox's backlog, A8's middleware group, A16's SEO seeding API, P16's Redirect API and I-220a/b (G4, G32); in the playground log, the watched G56, G58 and G62, and G61 (a Site dependency); in aguet, A18. G63 and G64 are fixed on Velox's `main` (a60e385) but not in a release yet.

---

## Issues judged not frictions

The 420 issues of `psyao/velox` (#1-#462; missing numbers are pull requests). 96 are cited above as frictions or their fixes: 74, 75, 100, 104, 117, 118, 120, 125, 127, 128, 129, 130, 131, 133, 134, 135, 137, 138, 141, 160, 193, 194, 195, 198, 209, 211, 212, 215, 217, 219, 220, 243, 255, 268, 269, 270, 271, 272, 273, 274, 275, 276, 277, 278, 279, 280, 281, 285, 286, 293, 299, 302, 304, 305, 306, 307, 320, 321, 336, 337, 338, 339, 341, 343, 344, 345, 346, 347, 348, 349, 350, 357, 359, 369, 370, 372, 373, 374, 375, 376, 377, 378, 379, 380, 382, 391, 392, 393, 403, 404, 405, 414, 417, 425, 430, 461. The other 324 record planning, decisions or planned work, not a friction met in use:

- **Maps** (wayfinder destinations): 1, 143, 175, 202, 238, 282, 309, 352, 383, 420, 431.
- **Research** (fact-finding, prior art): 2, 3, 101, 102, 103, 145, 176-182, 196, 204, 210, 221, 222, 233, 239, 256, 283, 284, 287, 311, 355, 385, 406, 432, 444.
- **Grilling and prototype decisions**: 4-9, 11-19, 105-107, 144, 146-159, 183-192, 197, 205-208, 213, 214, 216, 223-225, 228, 230, 234, 235, 240-242, 244-247, 257-262, 264-266, 288-292, 312-317, 322-324, 354, 356, 358, 387-389, 407-409, 412, 433-443, 445-448.
- **Specs**: 20, 35, 42, 50, 61, 66, 76, 85, 90, 109, 361, 394; 325 (aguet's spec, still open).
- **Planned features and chores from specs and maps**: 21-33, 36-40, 43-48, 51-57, 59, 60, 62-64, 67-73, 77-82, 84, 86-88, 91-98, 110-116, 122-124, 139, 142, 161-173, 200, 229, 232, 236, 248, 249, 294-298, 300, 301, 449-459, 462. (59 and 60 are defects found while designing the Media spec, before any use.)
- **Site migration steps and tasks** (their frictions are the log entries above): 10, 203, 226, 263, 267, 310, 318, 319, 326-333, 335, 353, 360, 362-368, 371, 384, 386, 390, 396-402, 410, 411, 421-424.
- **Open planning issues**: 227 (backlog of deferred features; it holds the open rows cited for A8, A16, P16, I-220), 334 (drop aguet's old tables, a planned step), 428 (plan for 1.0.0 then a less minimal Velox).

Every entry of the three migration logs and the playground log is listed above (A1-A25, P1-P17, S1-S4, G1-G64), as a row or merged into one; none was judged not a friction.
