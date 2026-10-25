# Candidate dependencies: licence, maintenance, shared-hosting fit

Research note for Kami (issue #4). Scope: every third-party package `docs/comparatif-cms.md` and `docs/velox-retrospective.md` §2.10 name as a candidate building block, with the facts the architecture's dependency table needs. No recommendation here: the choice belongs to the architecture ADRs.

- Facts only, from primary sources. Retrieved **2026-10-25**.
- "Latest stable" and every `require` constraint come from the Packagist metadata API, `https://repo.packagist.org/p2/<vendor>/<package>.json` (cited below as **[P]**). Repository facts (licence detected by GitHub, archived flag, issues enabled, last commit on the default branch) come from `https://api.github.com/repos/<owner>/<repo>` and `/commits` (cited as **[G]**). Issue trends come from GitHub search, `repo:<r> is:issue created:>=2026-04-25` and `closed:>=2026-04-25` (cited as **[S]**, window = last 6 months).
- Statements marked **(source)** come from reading the package's code on its default branch, not its prose documentation.
- Shared-hosting facts used for the "fit" column: `docs/velox-retrospective.md` §5.1 (Infomaniak, PHP 8.5.12: `proc_open`/`exec` disabled on the web SAPI and enabled in the CLI, `max_execution_time` 60 s on the web, `gd`/`imagick`/`exif`/`fileinfo`/`intl`/`mbstring` loaded, no Node on the server) and `docs/research/mutualise.md` (no worker, no Redis, file/database cache without tags).
- Reference versions: `laravel/framework` v13.38.0, `filament/filament` v5.10.3 (`docs/research/mutualise.md` §0).

## Licence summary

Every package below is **MIT** according to Packagist [P]. No copyleft, no "commons clause", no commercial-only licence: nothing restricts open-source or commercial distribution of Kami. One caveat:

- `kalnoy/nestedset`: `composer.json` declares `"license": "MIT"`, but the repository ships **no LICENSE file**, and GitHub reports no detected licence [G] (`https://api.github.com/repos/lazychaser/laravel-nestedset` → `license: null`). MIT requires the copyright notice to travel with the code; there is no notice to carry. Worth a line in the ADR if adopted.

## Main table

Columns: **L13 / F5** = does the latest stable release's constraint accept Laravel 13 / Filament 5 [P]. **Activity** = last commit on the default branch [G]; issues opened / closed in the last 6 months [S]; open issues now. **Fit** = shared-hosting fit (see notes for detail).

| Package | Licence | Latest stable (date) | PHP | L13 / F5 | Activity | Shared-hosting fit | Native alternative |
|---|---|---|---|---|---|---|---|
| `spatie/laravel-medialibrary` | MIT | 11.23.10 (2026-10-19) | `^8.2`; via `spatie/image` 3.9.9 `^8.4` | yes (`illuminate/* ^12.0\|^13.0`) / n/a | 2026-10-22; 8 / 9; 0 open | **Conditional.** Conversions are queued by default and optimised by default through `symfony/process` (`proc_open`), see note 1. Needs `ext-exif`, `ext-fileinfo` (both present) | `Storage` + Filament `FileUpload` + Laravel 13 `Image` facade (`intervention/image ^4`) |
| `awcodes/filament-curator` | MIT | v5.6.1 (2026-10-16) | `^8.2` | via Filament / yes (`filament/filament ^4.0\|^5.0`) | 2026-10-19; 9 / 10; 0 open | **Version conflict**: requires `league/glide ^3.0`, which requires `intervention/image ^3.9.1`; incompatible with Glide 4 and with Laravel 13's `Image` facade (`intervention/image ^4.0`), note 2 | Filament `FileUpload` + own media model |
| `league/glide` | MIT | 4.2.0 (2026-10-10) | `^8.3` | framework-agnostic / n/a | 2026-10-10; 1 / 6; 36 open | Pure PHP over Intervention 4 + Flysystem 3; GD or Imagick (both present). On-demand rendering runs in the web request (60 s budget) | Laravel 13 `Image` facade + own signed route |
| `intervention/image` | MIT | 4.4.0 (2026-10-17) | `^8.3`, `ext-mbstring` | framework-agnostic / n/a | 2026-10-25; 19 / 11; 23 open | Pure PHP, GD or Imagick (present); AVIF only through Imagick on Infomaniak (§5.1) | It *is* the engine behind Laravel 13's `Image` facade |
| `spatie/laravel-permission` | MIT | 8.3.2 (2026-10-19) | `^8.3` | yes (`^12.0\|^13.0`) / n/a | 2026-10-22; 8 / 9; 0 open | Pure PHP + DB tables; caches permissions in the default cache store (no tags required) | Gates and Policies; Filament authorises resources through policies |
| `bezhansalleh/filament-shield` | MIT | 4.3.1 (2026-07-25) | `^8.2\|^8.3` | yes (`^11.28\|^12.0\|^13.0`) / yes (`^4.0\|^5.0`) | 2026-10-18; 6 / 10; 2 open | Runtime is pure PHP. `shield:setup` uses `exec()` and the `Process` facade (source: `src/Commands/SetupCommand.php`): CLI only, works over SSH | Policies written by hand |
| `spatie/laravel-activitylog` | MIT | 5.1.3 (2026-10-19) | **`^8.4`** | yes (`^12.0 \|\| ^13.0`) / n/a | 2026-10-22; 3 / 3; 0 open | Pure PHP + DB table | Eloquent model events / observers writing to an own table |
| `spatie/laravel-csp` | MIT | 3.30.0 (2026-10-19) | `^8.3` | yes (`^12.0\|^13.0`) / n/a | 2026-10-22; 1 / 1; 0 open | Pure PHP middleware | `Vite::useCspNonce()` + an own middleware setting the header (laravel.com/docs/13.x/vite, "Content Security Policy (CSP) Nonce") |
| `spatie/laravel-translatable` | MIT | 6.15.0 (2026-10-19) | **`^8.4`** | yes (`^12.0\|^13.0`) / n/a | 2026-10-22; 3 / 3; 0 open | Pure PHP, JSON columns | JSON column + Eloquent cast / accessor |
| `filament/spatie-laravel-translatable-plugin` | MIT | v3.3.57 (2026-06-12) | `^8.1` | yes / **no**: Filament **3** line only | last commit 2026-06-12; issues disabled | **Abandoned** on Packagist, replacement named `lara-zeus/spatie-translatable` [P, `abandoned` field] | — |
| `lara-zeus/spatie-translatable` | MIT | 2.0.1 (2026-04-23) | `^8.1`, `ext-intl` | via Filament / yes (`filament/filament ^5.0`) | 2026-10-18; 5 / **0**; 10 open | Pure PHP; `intl` present | Own locale switcher on Filament forms |
| `spatie/laravel-sluggable` | MIT | 4.0.4 (2026-10-17) | `^8.3` | yes (`^12.0\|^13.0`) / n/a | 2026-10-22; 2 / 1; 1 open | Pure PHP | `Str::slug()` in a model event |
| `kalnoy/nestedset` | MIT (declared; no LICENSE file) | v7.0.0 (2026-04-11) | `^8.0` | yes (`illuminate/* >=13.0`, v7 is L13-only; v6.0.7 for older) / n/a | **2026-04-11**; 3 / **0**; **196 open** issues, 22 open PRs | Pure PHP | `parent_id` adjacency list (+ recursive CTE behind an abstraction); Filament has no tree component |
| `spatie/eloquent-sortable` | MIT | 5.0.1 (2026-02-21) | `^8.2` | yes (`^10.0\|…\|^13.0`) / n/a | 2026-06-26; 0 / 0; 0 open | Pure PHP | Integer `sort` column; Filament tables `->reorderable('sort')` (filamentphp.com/docs/5.x/tables/overview) |
| `spatie/laravel-settings` | MIT | 3.10.0 (2026-10-19) | `^8.2`, `ext-json` | yes (`illuminate/database ^12.0\|^13.0`) / n/a | 2026-10-22; 1 / 2; 0 open | Default repository is `database`; a `RedisSettingsRepository` exists but is optional (source: `config/settings.php`, `src/SettingsRepositories/`) | Own `settings` table + cache; Filament custom page |
| `ralphjsmit/laravel-seo` | MIT | 1.8.3 (2026-10-05) | `^8.2` | yes (`^11.11\|^12.0\|^13.0`) / n/a | 2026-10-05; 1 / 1; 4 open | Pure PHP + DB table | Blade partial for meta tags + columns on content |
| `spatie/laravel-sitemap` | MIT | 8.2.0 (2026-06-24) | **`^8.4`** | yes (`^12.0\|^13.0`) / n/a | 2026-10-22; issues disabled | Pulls `spatie/crawler ^9` (Guzzle, `^8.4`) even when the sitemap is built by hand. Crawl mode fetches the site over HTTP from inside a request (60 s budget); manual mode is pure PHP | XML Blade view returned by a route |
| `spatie/laravel-feed` | MIT | 4.5.0 (2026-02-21) | `^8.2` | yes (`^11.0\|^12.0\|^13.0`) / n/a | 2026-10-22; issues disabled | Pure PHP | Atom/RSS Blade view returned by a route |
| `spatie/laravel-honeypot` | MIT | 4.7.3 (2026-09-11) | `^8.2` | yes (`^11.0\|^12.0\|^13.0`) / n/a | 2026-10-22; issues disabled | Pure PHP | Hidden field + encrypted timestamp checked by a validation rule; `RateLimiter` |
| `spatie/laravel-tags` | MIT | 4.12.0 (2026-06-20) | `^8.1` | yes (`laravel/framework ^10.0\|…\|^13.0`) / n/a | 2026-10-22; 0 / 0; 0 open | Pure PHP. Also pulls `spatie/laravel-translatable ^6.0` and `spatie/eloquent-sortable` | `morphToMany` pivot |
| `spatie/laravel-responsecache` | MIT | 8.6.0 (2026-10-19) | **`^8.4`** | yes (`^12.0\|^13.0`) / n/a | 2026-10-22; 2 / 2; 0 open | **Conditional**: tags are opt-in (`RESPONSE_CACHE_TAG` empty by default). Without a tag, `clear()` calls `clear()` on the whole configured store (source: `src/ResponseCacheRepository.php`), note 4 | `cache.headers` middleware (laravel.com/docs/13.x/responses, "Cache Control Middleware") + `Cache::remember` |
| `ueberdosis/tiptap-php` | MIT | 2.2.1 (2026-10-19) | `^8.0` | framework-agnostic / **already required by `filament/forms` v5.10.3** (`^2.0`) | 2026-10-19; 0 / 0; 15 open | Requires `spatie/shiki-php ^2.0` → `symfony/process`; only the `CodeBlockShiki` node calls it, and Shiki runs `node` (source), note 3 | Filament 5 `RichEditor` (TipTap-based) and `RichContentRenderer` (filamentphp.com/docs/5.x/forms/rich-editor) |
| `mpociot/versionable` | MIT | 4.4.8 (2026-04-08) | `>=7.1.0 \|\| … \|\| >= 8.2` | yes (`illuminate/support ~5.3 \|\| … \|\| ^13.0`) / n/a | **2026-04-08**; 0 / 0; 22 open | Pure PHP + DB table | Own `revisions` table written by model observers |
| `laravel/scout` | MIT | v11.9.0 (2026-10-06) | `^8.0` | yes (`illuminate/* ^9.0\|…\|^13.0`) / n/a | 2026-10-14; issues disabled (Laravel org) | `SCOUT_QUEUE` defaults to `false` (sync indexing); default driver `collection` (source: `config/scout.php`). `database` engine supports **MySQL and PostgreSQL only**; `collection` works on SQLite too but "pulls all records and filters them in PHP" (laravel/docs `13.x/scout.md`) | First-party already. Without Scout: `whereFullText` (MySQL/PostgreSQL) or `LIKE` |
| `solution-forest/filament-tree` | MIT | 4.0.2 (2026-04-20) | `^8.1` | via Filament / yes (`filament/filament ^5.0`; 3.x line for Filament 4) | 2026-10-08; **issues disabled** on the repo [G] | Pure PHP / Livewire | Velox evidence: own UI audit and two "action does nothing" bugs (retrospective §2.10). Alternative: Filament table `->reorderable()` + `parent_id` select |

### Official Filament adapter plugins (same vendor as Filament)

| Package | Latest stable (date) | Requires | Note |
|---|---|---|---|
| `filament/spatie-laravel-media-library-plugin` | v5.10.3 (2026-10-06) | `spatie/laravel-medialibrary ^11.0` | Released in lockstep with Filament [P] |
| `filament/spatie-laravel-settings-plugin` | v5.10.3 (2026-09-07) | `spatie/laravel-settings ^3.0` | Idem |
| `filament/spatie-laravel-tags-plugin` | v5.10.3 (2026-10-06) | `spatie/laravel-tags ^4.0` | Idem |

There is no official Filament 5 translatable plugin: the official one stops at Filament 3 and points to `lara-zeus/spatie-translatable` [P].

## Notes

### 1. `spatie/laravel-medialibrary`: queue and `proc_open`

- Queue: `queue_conversions_by_default` defaults to `true`; `queue_connection_name` defaults to `env('QUEUE_CONNECTION', 'sync')` (source: `config/media-library.php`). With Kami's `database` queue, conversions wait for a worker unless each conversion calls `->nonQueued()` or the config is set to `false`.
- Optimisation: every `Conversion` is constructed with `->optimize($optimizerChain)` (source: `src/Conversions/Conversion.php`, constructor); `->nonOptimized()` removes it. The chain lives in `spatie/image-optimizer`, which runs the external binaries listed in `image_optimizers` (`jpegoptim`, `pngquant`, `optipng`, `svgo`, `gifsicle`, `cwebp`, `avifenc`; source: `config/media-library.php`) with `Process::fromShellCommandline()` (https://github.com/spatie/image-optimizer/blob/main/src/OptimizerChain.php).
- On the web SAPI, `symfony/process`'s constructor throws `LogicException` when `proc_open` does not exist (https://github.com/symfony/process/blob/8.0/Process.php, line 162). `OptimizerChain` catches any `Throwable` per optimiser, logs it and continues by default (same file, `applyOptimizer` / `throws()` doc-comment). Net effect on Infomaniak web: conversions succeed unoptimised and the log collects one error per optimiser per image. In the CLI, `proc_open` works, but the binaries must exist on the host (not measured).
- Image engine: medialibrary uses `spatie/image` (its own wrapper over GD / Imagick / Vips), **not** Intervention; `spatie/image` 3.9.9 requires PHP `^8.4` [P `spatie/image`].

### 2. `awcodes/filament-curator` blocks Intervention 4

- `awcodes/filament-curator` v5.6.1 requires `league/glide ^3.0` [P].
- `league/glide` 3.2.0 requires `intervention/image ^3.9.1`; Glide 4.x requires `intervention/image ^4.2` [P `league/glide`].
- Laravel 13's `Image` facade needs `intervention/image:^4.0` (`docs/research/mutualise.md` §5; https://laravel.com/docs/13.x/images#installation).
- Composer can install only one major of `intervention/image`, so Curator and the Laravel 13 `Image` facade (or Glide 4) cannot coexist in the same install as of 2026-10-25.

### 3. `ueberdosis/tiptap-php` is already in the tree

- `filament/forms` v5.10.3 requires `ueberdosis/tiptap-php ^2.0` [P `filament/forms`], so Kami gets it with Filament whether or not it is listed.
- `tiptap-php` requires `spatie/shiki-php ^2.0`, which requires `symfony/process` [P]. Only `src/Nodes/CodeBlockShiki.php` calls `Shiki::highlight()` (source), and `spatie/shiki-php` locates `node` with `ExecutableFinder` and runs it through `Process` (source: `src/Shiki.php`). On Infomaniak: no `proc_open` on the web and no Node on the server (§5.1), so that one node is unusable; the default `CodeBlock` node is not affected.

### 4. `spatie/laravel-responsecache` and tag-less stores

- When `responsecache.cache.tag` is empty (the default), `ResponseCacheRepository::clear()` calls `$this->cache->clear()` on the configured store; with a tag it calls `tags(...)->flush()` (source: `src/ResponseCacheRepository.php`, `src/ResponseCacheServiceProvider.php`). The `file` and `database` stores do not support tags (`docs/research/mutualise.md`), so on shared hosting a clear empties the whole store it is pointed at. `responsecache.cache.store` selects the store.

### 5. PHP floor

Latest releases requiring **PHP 8.4**: `spatie/laravel-activitylog` 5.1.3, `spatie/laravel-translatable` 6.15.0, `spatie/laravel-sitemap` 8.2.0 (and `spatie/crawler` 9.4.2), `spatie/laravel-responsecache` 8.6.0, `spatie/image` 3.9.9 (pulled by medialibrary) [P]. Laravel 13's own floor is 8.3 (`mutualise.md` §0). Infomaniak runs 8.5.12 (§5.1), so this is not a hosting blocker; it raises Kami's declared minimum to 8.4 if any of these is adopted at its latest version.

### 6. Maintenance signals worth naming

- `kalnoy/nestedset`: one release in 2026 (v7.0.0 / v6.0.7, both 2026-04-11), no commit since; 196 open issues and 22 open PRs; 0 issues closed in 6 months [G, S].
- `mpociot/versionable`: last commit and release 2026-04-08; 22 open issues; no issue activity in 6 months [G, S].
- `lara-zeus/spatie-translatable`: 5 issues opened, 0 closed in 6 months; 10 open [S].
- `solution-forest/filament-tree`: GitHub issues disabled on the repository [G], so there is no public tracker to check for known bugs.
- Spatie packages show 0 open issues across the board; several have GitHub issues disabled (`laravel-sitemap`, `laravel-feed`, `laravel-honeypot`) [G]. An empty tracker is a policy, not a bug count.

## Sources

- Packagist metadata: `https://repo.packagist.org/p2/<package>.json` for every package named above, plus `spatie/image`, `spatie/image-optimizer`, `spatie/shiki-php`, `spatie/crawler`, `spatie/temporary-directory`, `intervention/gif`, `filament/forms`, `laravel/framework`.
- Packagist package pages (abandoned flag, repository): `https://packagist.org/packages/<package>.json`.
- GitHub REST API: `https://api.github.com/repos/<owner>/<repo>`, `/commits?per_page=1`, `https://api.github.com/search/issues?q=repo:<owner>/<repo>+is:issue+...`.
- Source read on default branches (tarballs from `https://api.github.com/repos/<owner>/<repo>/tarball`): `spatie/laravel-medialibrary` (`config/media-library.php`, `src/Conversions/Conversion.php`), `spatie/image` (`src/Drivers/*`), `spatie/image-optimizer` (`src/OptimizerChain.php`), `symfony/process` 8.0 (`Process.php`), `spatie/shiki-php` (`src/Shiki.php`), `ueberdosis/tiptap-php` (`src/Nodes/CodeBlockShiki.php`), `spatie/laravel-responsecache` (`src/ResponseCacheRepository.php`, `src/ResponseCacheServiceProvider.php`), `spatie/laravel-settings` (`config/settings.php`), `laravel/scout` (`config/scout.php`), `bezhanSalleh/filament-shield` (`src/Commands/SetupCommand.php`), `lazychaser/laravel-nestedset` (`composer.json`, root listing).
- Docs: https://github.com/laravel/docs/blob/13.x/scout.md (Database / Collection Engines), https://laravel.com/docs/13.x/vite (CSP nonce), https://laravel.com/docs/13.x/responses (Cache Control Middleware), https://laravel.com/docs/13.x/images, https://filamentphp.com/docs/5.x/tables/overview (reordering), https://filamentphp.com/docs/5.x/forms/rich-editor, https://filamentphp.com/docs/5.x/users/overview (policies).
- Kami: `docs/velox-retrospective.md` §2.10 and §5.1, `docs/research/mutualise.md`.
