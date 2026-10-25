# Reference site: aguet.dev on Velox

Research note for Kami. Facts only, read from `~/Code/aguet` (branch `main`, HEAD `de1bb46`, "chore: upgrade Velox to 1.1 (#19)"). Every citation is `path:line` relative to `~/Code/aguet`; Velox citations point at the installed copy under `vendor/psyao/velox/` (v1.1.0), not at `~/Code/velox` (which is at v1.3.0). `VELOX-MIGRATION.md` is the site's own friction log: step N is on line 8+N, friction N on line 29+N. Live URLs were checked read-only with `curl` on 2026-10-25.

## 1. What the site is

- A one-page bilingual portfolio (French by default, English) for Steve Aguet, edited in a Filament admin. Its look is a dark terminal/IDE theme ("Terminal v2") (`README.md:3-10`).
- In production on Velox since the switch-over on 2026-10-20 (`VELOX-MIGRATION.md:21`), on Infomaniak shared hosting behind Apache (`server: Apache` on the live response; `DEPLOY.md:34-42`).
- Everything editorial lives in the database and is edited in Filament. UI chrome strings live in `lang/{fr,en}/site.php` (`README.md:46-48`, `README.md:82-84`).
- Production volumes at the switch-over: 7 projects, 5 skill groups, 26 tags, 47 tag classifications, one Home Page, one user (`VELOX-MIGRATION.md:20`).

## 2. Versions

| Package | Constraint | Locked |
| --- | --- | --- |
| PHP | `^8.5` (`composer.json:9`), platform pinned `8.5.0` (`composer.json:89-91`) | local CLI 8.5.12; production `/opt/php8.5/bin/php` (`.github/workflows/deploy.yml:120`, `scripts/deploy.sh:4`) |
| laravel/framework | `^13.37` (`composer.json:11`) | v13.38.0 |
| filament/filament | `^5.10` (`composer.json:10`) | v5.10.3 |
| psyao/velox | `^1.0` (`composer.json:13`), VCS repo `https://github.com/psyao/velox` (`composer.json:102-106`) | v1.1.0 |
| livewire/livewire | via Filament/Velox | v4.4.8 |
| ueberdosis/tiptap-php | via Velox | 2.2.1 |
| intervention/image | via Velox | 4.4.0 |
| solution-forest/filament-tree | via Velox | 4.0.2 |
| league/commonmark | via Laravel | 2.10.4 |
| nesbot/carbon | via Laravel | 3.15.0 |
| laravel/tinker | `^3.0` (`composer.json:12`) | v3.0.2 |
| laravel/boost (dev) | `^2.10` (`composer.json:17`) | v2.10.3 |
| pestphp/pest (dev) | `^5` (`composer.json:23`) | v5.3.3 |
| pestphp/pest-plugin-browser (dev) | `^5` (`composer.json:24`) | v5.1.2 |
| phpunit/phpunit (dev) | `^13` (`composer.json:25`) | 13.4.3 |

Front end: pnpm 11.10.0 (`package.json:5`), Node `>=24` (`package.json:7`) and `26` in `.nvmrc`/`.node-version`; Vite `^8.3.4`, Tailwind `^4.3.3`, laravel-vite-plugin `^3.2.0`, Playwright pinned `1.64.1` (`package.json:16-23`). Alpine is not installed separately: the Alpine shipped by Livewire 4 is used (`README.md:34-35`, `README.md:150-153`). JetBrains Mono is self-hosted at build time through the Vite Bunny font helper (`vite.config.js:11-16`).

Velox history on this site: `^0.23` through a symlinked path repository (`VELOX-MIGRATION.md:12`), `^0.24` (`VELOX-MIGRATION.md:13`), `^0.25` back on the VCS repository (`VELOX-MIGRATION.md:20`), `^0.29` (`VELOX-MIGRATION.md:24`), then 1.0 and 1.1 (commits `b115c05`, `de1bb46`; not logged in `VELOX-MIGRATION.md`). Before Velox the site already ran Laravel 13 and Filament 5 with `spatie/laravel-translatable` and `outerweb/filament-translatable-fields`, removed after the move (`VELOX-MIGRATION.md:9`, `VELOX-MIGRATION.md:22`).

Runtime config: `app.timezone` is `UTC` (`config/app.php:68`). The MySQL/MariaDB connections force `'timezone' => '+00:00'` (`config/database.php:61`, `config/database.php:82`). Velox's `display_timezone` is `Europe/Zurich` (`config/velox.php:41`). Queue, cache and session use the `database` driver (`.env.example:43`, `.env.example:45`, `.env.example:35`); mail defaults to `log` locally and SMTP in production (`.env.example:56-60`). `APP_LOCALE=fr`, `APP_FALLBACK_LOCALE=en` (`.env.example:7-8`).

## 3. Content model

Three Content Types are registered (`config/velox.php:13-17`). There are no Menus (`config/velox.php:24`). One Taxonomy is used.

### Home (singleton): `app/ContentTypes/Home.php`
- Labels « Page d'accueil » / « Accueil » (`app/ContentTypes/Home.php:27-35`). Public by default (it does not override `public()`). Its one Entry is the Home Page, read with `Entry::homePage()` and set with `Entry::setHomePage()` (`database/seeders/HomeSeeder.php:111`; `README.md:55-56`).
- Translatable fields (French Origin, English Translation) (`app/ContentTypes/Home.php:45-53`):
  - `hero_title`: Textarea, inline Markdown, a newline becomes `<br>`.
  - `hero_subtitle`: Text, inline Markdown.
  - `hero_role`, `hero_location`, `hero_exp`, `hero_focus`: Text.
  - `about_body`: RichText.
  - `contact_lead`: Text, inline Markdown.
- Untranslated fields, `->translatable(false)`, stored on the Origin (`app/ContentTypes/Home.php:54-59`): `contact_email` (also the address the contact form notifies), `contact_linkedin`, `contact_linkedin_label`, `contact_github`, `contact_github_label`.
- Velox's per-Entry `seo` (title and description per Locale) is filled by the seeder (`database/seeders/HomeSeeder.php:83-108`) and was filled by the content move (`database/migrations/2026_10_19_000000_move_content_into_velox.php:21-30`, `:100-101`).
- Entry titles are « Accueil » / « Home » (`database/seeders/HomeSeeder.php:96`, `:104`).
- `render()` is overridden to return the site's own `home` view (§5) (`app/ContentTypes/Home.php:66-79`).

### Project (collection, not public): `app/ContentTypes/Project.php`
- Labels « Projet » / « Projets »; the title is labelled « Nom » (`app/ContentTypes/Project.php:18-31`).
- `public()` is false: no URL of its own, only listed on the Home Page (`app/ContentTypes/Project.php:12-14`, `:38-41`).
- `orderable()` is true: ordered by hand by dragging rows in the admin (`app/ContentTypes/Project.php:43-46`, `README.md:104`).
- Its Entry model is `App\Models\Project` (`app/ContentTypes/Project.php:48-51`). That model extends Velox's `Entry` and adds `host()`, which takes the host of `url` and strips `www.` (`app/Models/Project.php:8-22`).
- Taxonomy: `Tag` (`app/ContentTypes/Project.php:53-56`).
- Fields (`app/ContentTypes/Project.php:60-67`): `client`, `role` (Text, translatable); `summary` (Textarea, translatable, inline Markdown); `url` (Text, untranslated); `featured` (Boolean « Projet phare », untranslated).
- Visibility: only published Entries render; a Draft is left off the page (`README.md:107`; tested at `tests/Feature/HomePageTest.php:197-202`). The move turned the old `is_published = false` rows into Drafts (`database/migrations/2026_10_19_000000_move_content_into_velox.php:62`).
- Sorting and featured: the view puts featured Projects first, then the others in hand order (`resources/views/sections/projects.blade.php:4-5`, `:15-24`); tested at `tests/Feature/HomePageTest.php:185-195`.
- Slug: each Locale has its own slug (a Translation's slug comes from its title). The card legend `~/projects/{slug}` reads the Origin's slug so it is the same in both languages (`resources/views/partials/project-card.blade.php:4`; `VELOX-MIGRATION.md:40`; tested at `tests/Feature/HomePageTest.php:204-212`). Seeded slugs: `cvci`, `animalia`, `atr-booking`, `cvci-125`, `montreux-noel-2023`, `terre-et-nature`, `vaud-rando` (`database/seeders/ProjectSeeder.php:26-113`).

### SkillGroup (collection, not public): `app/ContentTypes/SkillGroup.php`
- Labels « Groupe de compétences » / « Compétences » (`app/ContentTypes/SkillGroup.php:17-25`). Not public (`:32-35`). Orderable (`:37-40`). Taxonomy `Tag` (`:42-45`).
- Fields (`app/ContentTypes/SkillGroup.php:49-55`):
  - `text`: translatable. When set, it renders instead of the Tags (used by the « Langues » group).
  - `note`: translatable. Shown in the tree footer for the focus group.
  - `focus`: Boolean « Groupe ★ », untranslated.
- Rendering: `resources/views/sections/skills.blade.php:3-4`, `:13-29`.

### Tag (Taxonomy): `app/Taxonomies/Tag.php`
- Flat, untranslated (`translatable()` false), not public: no Archive (`app/Taxonomies/Tag.php:13-21`).
- An Entry keeps its Tags in the order they were picked (`README.md:76`). `Home::listed()` loads `allTerms` and `origin.allTerms` and sets each Entry's `tags` relation from `terms(Tag::class)` (`app/ContentTypes/Home.php:87-91`). Tag order is tested at `tests/Feature/HomePageTest.php:225-234`.

### Non-Velox model: ContactMessage
- Table `contact_messages`: `subject` (150), `email`, `message`, `read_at`, `notified_at`, `notify_attempts`, timestamps (`database/migrations/2026_06_13_120000_create_contact_messages_table.php:19-30`). Later additions: `kchat_notified_at`, `kchat_notify_attempts` (`database/migrations/2026_06_13_130000_add_kchat_delivery_to_contact_messages.php:17-22`).
- Only `subject`, `email` and `message` are fillable (`app/Models/ContactMessage.php:22`).

### Former schema (pre-Velox)
- Before Velox, content lived in the site's own tables: `site_contents`, `projects`, `skill_groups`, `tags` and a `taggables` pivot with `position`, using spatie JSON translations. A one-shot migration moved it all into Velox in one transaction, with a count check (`database/migrations/2026_10_19_000000_move_content_into_velox.php:32-116`). The old tables were left in place (`VELOX-MIGRATION.md:19`).
- A blank English value took the French one, "Velox has no per-Field fallback" (`database/migrations/2026_10_19_000000_move_content_into_velox.php:123-137`).

## 4. Blocks and rich text

- No Blocks are used. The site has no `Blocks` field, and `RichText::make('about_body')` is declared without `->blocks()` (`app/ContentTypes/Home.php:52`). Velox's RichText does support Blocks (`vendor/psyao/velox/src/Fields/RichText.php:78`).
- `about_body` is the only rich text. It is stored as a TipTap document, never HTML (`vendor/psyao/velox/src/Fields/RichText.php:45-48`), and cast to a `RenderedRichText` (an `HtmlString`) (`vendor/psyao/velox/src/Fields/RichText.php:113`; `vendor/psyao/velox/src/Fields/RenderedRichText.php:11`). The view echoes it with `{{ }}` (`resources/views/sections/about.blade.php:8`).
- Its pre-Velox Markdown was converted to a TipTap document with `Str::markdown()` then `RichContentRenderer::toArray()` (`database/migrations/2026_10_19_000000_move_content_into_velox.php:89-92`).
- Inline Markdown in plain Text/Textarea fields (`hero_title`, `hero_subtitle`, `contact_lead`, `summary`) goes through the site's own `App\Support\Content` (`app/Support/Content.php:10-31`):
  - `Str::inlineMarkdown` with `html_input: strip` and `allow_unsafe_links: false`.
  - `*mot*` renders as an accent `<em>`, `**mot**` as bold.
  - `heroTitle()` adds `nl2br`.
- That syntax is documented to editors in the admin help texts (`app/ContentTypes/Home.php:25`, `:46-53`).
- A past migration converted the hero title's accent syntax (`database/migrations/2026_06_27_000000_convert_hero_title_accent_syntax.php`; `VELOX-MIGRATION.md:38`).

## 5. Templates and views

| View | Renders |
| --- | --- |
| `resources/views/layouts/app.blade.php` | Shell. `<html lang>`, screenshot-mode switch (`:6`, `:56-77`), `<x-velox::seo />` (`:79`), `og:locale` and its alternates (`:80-83`), Livewire styles and Vite assets (`:85-86`), Person JSON-LD (`:88-101`), Cloudflare Web Analytics beacon when a token is set (`:104-107`), theme restore (`:113`), an HTML source comment listing the hidden keyboard features (`:116-125`), skip link, then chrome, tabs, `main`, statusbar, palette, contact modal (`:127-138`), `window.__AGUET` JS config with i18n strings, projects and base64 email (`:8-47`, `:140`) |
| `resources/views/home.blade.php` | Extends the layout; includes sections hero, about, skills, projects, contact (`:1-9`) |
| `resources/views/sections/hero.blade.php` | Typed `whoami` intro; `hero_role`, `hero_title` via `Content::heroTitle`, `hero_subtitle`, a key/value list (location/exp/focus), anchors `#projects` and `#contact` (`:1-33`) |
| `resources/views/sections/about.blade.php` | `about_body` rich text plus an ASCII "fastfetch" card using `hero_location`, `hero_exp` and the focus SkillGroup's title (`:1-42`) |
| `resources/views/sections/skills.blade.php` | `tree ~/stack`: SkillGroups with Tag counts, focus ★, footer with group and tech counts and the focus note (`:1-31`) |
| `resources/views/sections/projects.blade.php` | `ls -l ~/projects`: featured cards, then the rest; a lone trailing odd card spans full width (`:1-27`) |
| `resources/views/partials/project-card.blade.php` | Card variants `feat`, `full`, `normal`: Origin slug legend, title, client, role, Tags, summary, link to `url` showing `host()` (`:1-49`) |
| `resources/views/sections/contact.blade.php` | `contact.json` block: email base64-encoded and rebuilt by Alpine, LinkedIn, GitHub, location; CTA opens the modal, or falls back to LinkedIn without JS (`:1-41`) |
| `resources/views/partials/chrome.blade.php` | Window bar, ⌘K button, FR/EN switch built from `$homeUrls` with `hreflang` (`:1-20`) |
| `resources/views/partials/tabs.blade.php` | Section nav: `#about`, `#skills`, `#projects`, `#contact` (`:1-9`) |
| `resources/views/partials/statusbar.blade.php` | tmux-style footer: vim-mode pill, branch/commit popover from `config/build_info.php`, locale switch link to `$altUrl`, clock, year (`:1-64`) |
| `resources/views/partials/command-palette.blade.php` | ⌘K palette markup; state in Alpine `$store.cmdk` |
| `resources/views/partials/contact-modal.blade.php` | Modal shell around `<livewire:contact-form />` (`:1-22`) |
| `resources/views/livewire/contact-form.blade.php` | The form (`wire:submit="submit"`), honeypot `website` (`:93-94`), delivery progress polled with `wire:poll.1s="refreshDelivery"` (`:13`) |
| `resources/views/emails/contact-message.blade.php` | Plain-text owner email, « Reçu le » in the Display Time Zone (`:1-12`) |
| `resources/views/errors/_shell.blade.php` + `403/404/419/429/500/503.blade.php` | Self-contained error page (see §8.3) |
| `resources/views/filament/pages/database-backup.blade.php` | Admin backup page |

Front-end JS: `resources/js/app.js` (482 lines) and `resources/js/easter-eggs.js` (178 lines). They provide the Alpine components `terminal`, `clock`, `copy`, `email` and `statusbar`, and the stores `$store.cmdk`, `$store.vim` and `$store.contact` (`README.md:153-170`). The palette's language action navigates to `CFG.altUrl` (`resources/js/app.js:216`). Five colour schemes are set with `:colorscheme` and kept in `localStorage` (`README.md:185-196`).

No `resources/views/velox` views remain: `velox:install` was trimmed (Page, Post, Category, Menus and all its views removed) (`VELOX-MIGRATION.md:12`).

## 6. URL structure

### How URLs are resolved
- `routes/web.php` declares no route (`routes/web.php:1-7`). The public pages are served by Velox's catch-all `GET {path}` → `EntryController`, named `velox.entry`, in the `web` group, registered as a fallback (`vendor/psyao/velox/routes/web.php:42-46`).
- `EntryController` lowercases the path and strips the trailing slash. A non-canonical request answers 301 to the canonical form, or to a stored Redirect (`vendor/psyao/velox/src/Http/Controllers/EntryController.php:29-38`; `vendor/psyao/velox/src/Paths.php:104-107`). It then sets the Locale from the path (`EntryController.php:40`).
- A Locale's prefix serves the Home Page: `''` for the default `fr`, `en` for English (`EntryController.php:42-44`). Other paths are looked up as public Archives, visible Entries by `path`, Term Archives, Redirects, then feeds; otherwise 404 (`EntryController.php:45-65`).
- An Entry whose type is not public answers 404 (`EntryController.php:69`). Project and SkillGroup are not public, and Tag is not public either.
- Since Velox 0.27, Laravel's trailing-slash redirect block left `public/.htaccess`. A URL ending in `/` answers one 301 from Velox (`VELOX-MIGRATION.md:24`; `public/.htaccess:1-24`).
- `public/.htaccess` also 301s `www.` to the apex (`public/.htaccess:8-10`). It sets `Cache-Control: public, max-age=31536000, immutable` on `/build/*` (`public/.htaccess:29-33`).
- Locales: `fr` « Français » (default, unprefixed) and `en` « English » (`config/velox.php:31-34`). The language switch and `hreflang` URLs come from `Velox::locales()` (`app/ContentTypes/Home.php:68-78`).

### Every public route (from `php artisan route:list`, plus live checks)

| Path | Source | Behaviour | Live 2026-10-25 |
| --- | --- | --- | --- |
| `/` | `velox.entry` | Home Page, French Origin | 200 |
| `/en` | `velox.entry` | Home Page, English Translation | 200 |
| `/en/`, `/EN` | `velox.entry` canonicalisation | 301 → `/en` | 301 → `https://aguet.dev/en` |
| `/fr` | `velox.entry` | not a prefix (default Locale is unprefixed) | 404 |
| any other path, e.g. `/nope`, `/en/nope`, `/accueil`, `/home` | `velox.entry` | site's themed 404 (`tests/Feature/HomePageTest.php:97-102`) | 404 |
| `/sitemap.xml` | `velox.sitemap` (`vendor/psyao/velox/routes/web.php:38-40`) | lists `/` and `/en` with `hreflang` fr/en/x-default (`tests/Feature/HomePageTest.php:150-167`) | 200, two `<url>` |
| `/robots.txt` | static `public/robots.txt` (`public/robots.txt:1-4`); Velox's `velox.robots` route (`vendor/psyao/velox/routes/web.php:34-36`) is shadowed wherever Apache serves the file | `Disallow: /admin`, `Sitemap: https://aguet.dev/sitemap.xml` | 200, same bytes |
| `/cron/{token}` | `bootstrap/app.php:21-22`, `token` must match `[a-f0-9]{48}`, no middleware | see §8.2 | `/cron/abc` 404 |
| `/up` | Laravel health route (`bootstrap/app.php:15`) | 200 | 200 |
| `/storage/...` | Apache via the `public/storage` symlink (`scripts/deploy.sh:37`); Velox media on the `public` disk | e.g. `/storage/media/01m5pgcpaafgk30py2pce8c412/aguet-dev-1200.webp` | 200 `image/webp` |
| `/storage/{path}` (GET, PUT) | Laravel `storage.local` (local disk `serve => true`, `config/filesystems.php:33-36`) | Laravel's local-disk route (`route:list`) | not probed |
| `/build/*`, `/favicon.ico`, `/index.php` | static files / front controller | `/index.php` serves the home page | 200 |
| `/livewire-98736c6e/update`, `/livewire-98736c6e/livewire.js`, etc. | Livewire 4 (hashed prefix) | the contact form posts here (route list) | not probed |
| `/filament/exports/{export}/download`, `/filament/imports/{import}/failed-rows/download` | Filament | auth-gated downloads | not probed |
| `/_boost/browser-logs` (POST) | laravel/boost, dev dependency (`composer.json:17`) | dev only; `composer install --no-dev` in production (`scripts/deploy.sh:27`) | — |

In-page anchors (not routes): `#about`, `#skills`, `#projects`, `#contact`, `#content` (`resources/views/partials/tabs.blade.php:4-7`; `resources/views/layouts/app.blade.php:127`). `?screenshot=1` is honoured only under `APP_ENV=testing` (`resources/views/layouts/app.blade.php:6`).

### Admin routes (`/admin`, panel `admin`, `app/Providers/Filament/AdminPanelProvider.php:27-29`)
- Velox: `admin/entries/{home,project,skill-group}` (+ `/create`, `/tree`, `/{record}/edit`), `admin/terms/tag` (+ create/edit), `admin/media` (+ edit), `admin/menus`, `admin/redirects`, `admin/seo`, `admin/accounts` (+ invite/edit), `admin/invitation`, `admin/profile`, `admin/preview/{token}` (`velox.preview`, `vendor/psyao/velox/routes/web.php:15-21`).
- Filament: `admin` (dashboard), `admin/login`, `admin/logout`, `admin/password-reset/*`, `admin/email-change-verification/*`.
- Site's own: `admin/contact-messages` (`app/Filament/Resources/ContactMessages/ContactMessageResource.php:56-61`), `admin/database-backup` (`app/Filament/Pages/DatabaseBackup.php:20-34`).
- Live: `/admin` 302 → `/admin/login` (200).

## 7. Multilingual handling

- Locales are declared once in `config/velox.php:31-34`. The English home is the Home Page's English Translation; fields marked `translatable(false)` are read from the Origin (`README.md:52-77`; tested at `tests/Feature/HomePageTest.php:45-54`).
- The view receives `locale`, `altUrl` (the other Locale's URL) and `homeUrls` (code → URL) (`app/ContentTypes/Home.php:71-78`). The chrome switch and the statusbar switch link to them (`resources/views/partials/chrome.blade.php:12-16`; `resources/views/partials/statusbar.blade.php:61`; tested at `tests/Feature/HomePageTest.php:56-63`).
- UI strings come from `lang/fr/site.php` and `lang/en/site.php`. Error-page copy comes from `site.errors.{code}.title|message` (`lang/fr/site.php:137-157`).
- `<x-velox::seo />` emits title, description, canonical, `hreflang` fr/en/x-default, Open Graph and Twitter card per Locale. The layout adds `og:locale` `fr_CH`/`en_GB` and its alternate itself (`resources/views/layouts/app.blade.php:7`, `:79-83`); tested tag by tag at `tests/Feature/HomePageTest.php:104-148`.
- The error pages derive the Locale from the first URL segment themselves (§8.3).
- The admin is French only, with `APP_FALLBACK_LOCALE=en` so Filament strings missing in French fall back to English (`VELOX-MIGRATION.md:43`).
- Tags have one name in all Locales (`app/Taxonomies/Tag.php:13-16`).

## 8. Specific needs

### 8.1 Contact form and notification chain
- The form is the Livewire component `App\Livewire\ContactForm`, mounted in an Alpine modal (`resources/views/partials/contact-modal.blade.php:19`).
- Validation: `subject` required, max 150; `email` `email:rfc`, max 255; `message` max 5000 (`app/Livewire/ContactForm.php:86-93`). Messages are translated (`:96-113`).
- Anti-spam, all inside the component, because route `throttle` does not reach Livewire's update endpoint (`app/Livewire/ContactForm.php:24-28`):
  - Per-IP rate limit: 5 attempts per 60 s (`:66-68`, `:122-130`). The key collapses IPv6 to its /64 (`:270-279`).
  - Honeypot `website` and a 2 s minimum fill time (`:134-141`). A rejected submit sees the same success screen.
- No `trustProxies`: Infomaniak passes the real IP in `REMOTE_ADDR` (`bootstrap/app.php:28-32`).
- The row is written first (DB-first): `ContactMessage::create()` with the subject stripped of control characters (`app/Livewire/ContactForm.php:145-156`, `:282-285`).
- Delivery then runs in `defer(fn () => $notifier->deliver($message))`, after the response, in the same process: the host has no queue worker and `proc_open` is disabled (`app/Livewire/ContactForm.php:14-22`, `:161`).
- The success view polls `refreshDelivery()` about once a second, up to 12 times, to animate per-rail progress (`app/Livewire/ContactForm.php:73`, `:178-203`). `enableBackButtonCache()` keeps the page out of `no-store` (`:79-82`; tested at `tests/Feature/HomePageTest.php:85-95`).
- `ContactMessageNotifier` runs two independent rails, each with its own flag and attempt counter, `MAX_ATTEMPTS = 5`, at-least-once (`app/Services/ContactMessageNotifier.php:15-33`, `:40-54`):
  - **Email**: to the Home Page's `contact_email` (`:57-60`). `ContactMessageMail` is plain text, subject `[Contact] …`, visitor on `Reply-To` (`app/Mail/ContactMessageMail.php:28-46`). A blank recipient logs a warning (`app/Services/ContactMessageNotifier.php:45-46`).
  - **kChat**: a custom notification channel posts a Mattermost attachment to `services.kchat.contact_webhook_url` (`app/Notifications/ContactMessageReceived.php:23-41`; `config/services.php:38-41`). The channel accepts only http/https and redacts the URL from errors; timeouts 3 s/8 s with 2 retries (`app/Notifications/Channels/KChatChannel.php:20-57`). Visitor text is neutralised against @mentions and Markdown (`app/Notifications/Channels/KChatMessage.php:79-86`). The card links to the admin inbox (`app/Notifications/ContactMessageReceived.php:35`).
- Backstop sweep: `contact:notify` takes pending rows, at most 50 per run, and calls the same rails (`app/Console/Commands/NotifyContactMessages.php:36-90`).
- Admin inbox `ContactMessageResource`:
  - Read-only, no create (`app/Filament/Resources/ContactMessages/ContactMessageResource.php:15-46`).
  - Unread badge (`:49-54`).
  - Dates shown in the Display Time Zone (`app/Filament/Resources/ContactMessages/Tables/ContactMessagesTable.php:28`).
  - Dashboard widgets `StatsOverview` (unread, published and featured projects; `app/Filament/Widgets/StatsOverview.php:20-25`) and `LatestMessages` (`app/Filament/Widgets/LatestMessages.php:13-15`).

### 8.2 `/cron/{token}` web scheduler
- Infomaniak has no real crontab, only a URL-based "Planificateur de tâches" with a 15-minute minimum interval (`routes/console.php:11-15`; `docs/explanation-contact-pipeline.md:9-24`). The `Schedule::command('contact:notify')->everyFifteenMinutes()->withoutOverlapping()` entry (`routes/console.php:15`) is inert in production: nothing runs `schedule:run` (`docs/explanation-contact-pipeline.md:75-77`).
- The route is registered in the `then:` callback of `withRouting`, outside the `web` group (no session, cookies or CSRF), with `token` matching `[a-f0-9]{48}` (`bootstrap/app.php:16-23`). Tested: no session cookie (`tests/Feature/CronControllerTest.php:144`).
- `CronController` (`app/Http/Controllers/CronController.php:29-87`):
  1. A `HEAD` request answers 404 (`:31-33`).
  2. The token is compared with `hash_equals` against `config('aguet.cron.token')` (env `CRON_TOKEN`). A wrong, blank or non-string value answers 404 (`:35-39`; `config/aguet.php:34-36`).
  3. It takes a non-blocking `Cache::lock('cron:contact-notify', 240)`. If the lock is held it answers `200 BUSY` (`:23-25`, `:44-50`).
  4. It runs `Artisan::call('contact:notify')` directly, not `schedule:run`. A non-zero exit answers `500 FAILED` (`:52-61`).
  5. On success it writes `cron:contact-notify:last-success` for 2 days and answers `200 OK` (`:63-69`). Any exception answers `500 FAILED`; a failed lock release is only logged (`:70-86`).
  6. Every response carries `Cache-Control: no-store` (`:89-92`).
- The token is generated by `php artisan cron:token`: 24 random bytes as hex; `--force` writes `CRON_TOKEN=` into `.env` (`app/Console/Commands/GenerateCronToken.php:15-71`). Rotation procedure: `docs/howto-rotate-cron-token.md:16-37`, configured at `https://aguet.dev/cron/<token>`.
- Test coverage: 16 cases CC1–CC14 (`tests/Feature/CronControllerTest.php:36-282`).

### 8.3 Error pages
- `errors/{403,404,419,429,500,503}.blade.php` each include `errors/_shell` with only the status code (`resources/views/errors/404.blade.php:1`; `docs/explanation-error-pages.md:3-7`).
- The shell has no `@vite`, no Livewire and no JS. Its CSS is inline and duplicates the theme tokens, so it renders without a build or a database (`resources/views/errors/_shell.blade.php:1-6`, `:29-40`). It adds `noindex` (`:27`).
- The Locale is resolved from `request()->segment(1)` against `config('velox.locales')`, because Velox sets the Locale only on its own pages (`resources/views/errors/_shell.blade.php:8-17`; `docs/explanation-error-pages.md:35-43`).
- Tests: every code, `/en/*` 404 in English, a 503 from maintenance mode, a 500 with no Vite build (`tests/Feature/ErrorPagesTest.php:20-78`).
- Open item: behaviour of the 419 page when the session store is unreachable is unverified (`TODOS.md:5-15`).

### 8.4 Visual regression tests
- Pest browser tests (Playwright/Chromium) live under `tests/Browser`, run by `composer test:visual` with `phpunit.browser.xml` on a file-backed SQLite database seeded before each test (`composer.json:56-61`; `phpunit.browser.xml:7-24`; `docs/visual-testing.md:37-40`).
- Baselines (`tests/.pest/snapshots/Browser/`):
  - Home FR/EN, desktop and mobile (`tests/Browser/HomeVisualTest.php:8-43`).
  - Contact modal FR/EN, desktop and mobile (`tests/Browser/ContactModalVisualTest.php:14-69`).
  - Command palette open (`tests/Browser/InteractiveVisualTest.php:7-20`).
  - French 404 at `/this-route-does-not-exist` (`tests/Browser/ErrorPageVisualTest.php:7-13`).
- Print is skipped (`tests/Browser/InteractiveVisualTest.php:26`; `docs/visual-testing.md:65-71`). Smoke and screenshot-mode tests: `tests/Browser/SmokeTest.php:7`, `tests/Browser/ScreenshotModeTest.php:6`.
- Screenshot mode turns off animations and `backdrop-filter`, freezes clock and year, and forces JetBrains Mono over Pest's injected Arial (`resources/views/layouts/app.blade.php:56-77`; `resources/views/partials/statusbar.blade.php:62-63`).
- Baselines are tied to one machine (macOS 26.6.2, Playwright 1.64.1, Chromium v1248). The suite does not run in CI (`docs/visual-testing.md:73-83`).
- A failed suite reruns once, because Pest compares bytes and Chromium's anti-aliasing under load causes flakes (`composer.json:60`; `VELOX-MIGRATION.md:23`, friction 20 at `VELOX-MIGRATION.md:49`).
- `BROWSER_KEEP_DATABASE=1` captures an existing database such as a production dump. It was used to prove the Velox switch-over byte-identical (`docs/visual-testing.md:42-45`; `VELOX-MIGRATION.md:20`).

### 8.5 GitHub Actions deploy to shared hosting
- **CI** (`.github/workflows/ci.yml`) runs on push and PR to `main` (`:3-7`): PHP 8.5 with SQLite (`:23-28`), `composer install` with the `COMPOSER_AUTH` secret for the private Velox repo (`:49-52`), pnpm build (`:59-62`), `pint --test` (`:64-65`), `php artisan test` (`:67-68`).
- **Deploy** (`.github/workflows/deploy.yml`) is triggered by `workflow_run` of CI on `main` and only runs when CI concluded `success` (`:11-15`, `:36`). Concurrency group `deploy` (`:21-23`).
  - `build` job, no high-value secrets:
    - Harden-runner in audit mode (`:42-49`).
    - `setup-stack`: PHP 8.5, `composer validate --strict`, `composer install --no-scripts`, `composer audit --no-dev`, `pnpm install --frozen-lockfile`, `pnpm run build` (`.github/actions/setup-stack/action.yml:7-61`).
    - Writes `config/build_info.php` (SHA, base64 subject, ISO date; validated) (`.github/workflows/deploy.yml:60-90`).
    - Tars the release: app, bootstrap, config, database, lang, public, resources, routes, scripts, artisan, composer files (`:92-99`).
  - `deploy` job, environment `production`:
    - `.env` from Doppler (`:151-154`).
    - Pinned `known_hosts` (`:156-164`).
    - rsync with `--delete`, an include list and `--exclude='*'`, protecting `public/storage`, `public/css`, `public/js` (`:166-189`). `vendor/` and `storage/` are never synced (`DEPLOY.md:30`).
    - Runs `scripts/deploy.sh` over SSH, piping `COMPOSER_AUTH` on stdin (`:191-200`).
  - `notify` job: always runs, posts to kChat (`:206-234`).
- **`scripts/deploy.sh`**, in order:
  1. Reads the Composer token from stdin (`:15-21`).
  2. `composer2.phar install --no-dev --optimize-autoloader` before maintenance mode (`:26-27`).
  3. `artisan down`, with a trap that runs `artisan up` on exit (`:29-35`).
  4. `storage:link --force` (`:37`), `migrate --force` (`:38`), `velox:fields:check` (`:39`), `optimize` (`:40`), `queue:restart` (`:41`).
- Host constraints: no Node, docroot must be `public/`, the CLI PHP may differ from the web PHP, `proc_open` is needed for Composer (`DEPLOY.md:34-42`). `DEPLOY.md` still says PHP 8.4 / `/opt/php8.4/bin/php` (`DEPLOY.md:40`, `DEPLOY.md:95`); the workflow and script use 8.5 (`.github/workflows/deploy.yml:120`, `scripts/deploy.sh:4`).
- Build info shows in the statusbar commit popover, linked to `config('aguet.repo_url')` (`resources/views/partials/statusbar.blade.php:4-22`; `config/aguet.php:25`).

### 8.6 SEO, meta, sitemap, analytics, security headers
- SEO head comes from Velox `<x-velox::seo />`: per-Entry `seo` title and description per Locale, site name from `APP_NAME` (`VELOX-MIGRATION.md:17`, friction 16 at `VELOX-MIGRATION.md:45`). Velox emits `noindex` outside production (`vendor/psyao/velox/resources/views/components/seo.blade.php:15-16`).
- Live, a default social image is set in the SEO settings (a Velox Media item): `og:image` `…/storage/media/01m5pgcpaafgk30py2pce8c412/aguet-dev-1200.webp` 1200×630, so `twitter:card` is `summary_large_image` (`vendor/psyao/velox/resources/views/components/seo.blade.php:42`; `vendor/psyao/velox/src/Seo.php:84-90`). The feature test's database has none and asserts `summary` and no `og:image` (`tests/Feature/HomePageTest.php:140`, `:146`).
- Site additions to the head:
  - `og:locale` and its alternate (`resources/views/layouts/app.blade.php:80-83`).
  - `Person` JSON-LD with `jobTitle` from `hero_role` and `sameAs` from LinkedIn/GitHub (`:88-101`).
  - Cloudflare Web Analytics beacon, cookieless (`:103-107`; `config/services.php:44-47`).
- Sitemap from Velox: `/` and `/en`, with `lastmod` and alternates; lastmod `2026-06-27T13:58:42+00:00` live.
- `robots.txt`: static file (§6). In tests, Velox's route says `Disallow: /admin` + `Sitemap` in production and `Disallow: /` elsewhere (`tests/Feature/HomePageTest.php:169-183`; `vendor/psyao/velox/src/Http/Controllers/RobotsController.php:22`).
- `SetSecurityHeaders` is appended to the `web` group (`bootstrap/app.php:26`). It applies only to `velox.entry` and `velox.preview` (`app/Http/Middleware/SetSecurityHeaders.php:25-30`):
  - CSP with `'unsafe-inline'` and `'unsafe-eval'` for Alpine, plus the Cloudflare origins (`:32-47`).
  - `Cross-Origin-Opener-Policy: same-origin` (`:52`).
  - Tested at `tests/Feature/HomePageTest.php:74-83`.
- The contact email is never in the HTML as plain text: it is base64 in the page and rebuilt by Alpine (`resources/views/sections/contact.blade.php:4-6`, `:21-23`; `resources/views/layouts/app.blade.php:39-41`).

### 8.7 Other site-specific pieces
- Admin panel: brand `aguet.dev`, Emerald colour, `VeloxPlugin`; discovers the site's Resources, Pages and Widgets (`app/Providers/Filament/AdminPanelProvider.php:25-56`). `User` is a Velox Account; every user is a Developer (`VELOX-MIGRATION.md:12`; `database/migrations/2026_10_19_000000_move_content_into_velox.php:105`).
- Database backup page « Sauvegarde DB »:
  - Lives in Velox's Settings group, gated by `Capability::ManageSettings` (`app/Filament/Pages/DatabaseBackup.php:24-35`).
  - The dumper is pure PHP and needs no `mysqldump` binary; the dump starts with `SET NAMES utf8mb4` (`app/Support/DatabaseDumper.php:8-22`).
  - The restorer drops every table, imports, migrates, then restores the admin row (`app/Support/DatabaseRestorer.php:26-47`; `VELOX-MIGRATION.md:46`).
  - Media files are not included (friction 18, `VELOX-MIGRATION.md:47`).
  - There is also a CLI script `scripts/backup-db.sh` (`README.md:269-283`).
- Dates: the connection runs in UTC and a one-off migration converted every TIMESTAMP column previously stored in CEST (`database/migrations/2026_10_23_000000_store_dates_in_utc.php:8-31`). Another migration restored the moved Entries' original dates (`database/migrations/2026_10_20_000000_restore_the_moved_entries_dates.php`; friction 23 at `VELOX-MIGRATION.md:52`).

## 9. Media

- The front end renders no `<img>` (no match in `resources/views`). Its only visual assets are CSS, the self-hosted JetBrains Mono (weights 400/500/700, `vite.config.js:13-15`) and `public/favicon.ico`.
- Velox's Media Library is present (`admin/media`). It holds at least the default social image served live at `/storage/media/{ulid}/aguet-dev-1200.webp` (§8.6).
- The site does not configure media: `config/velox.php` has no `media` key, so Velox defaults apply (`vendor/psyao/velox/config/velox.php:39-68`):
  - Files are served from the `public` disk; originals are kept on the private `local` disk.
  - JPEG, PNG and WebP get WebP variants at widths 320/640/1024/1600/2560; other accepted types are served as uploaded.
  - URLs are fixed at upload as `media/{ulid}/{slug}.{ext}`, variants as `media/{ulid}/{slug}-{width}.webp` (`vendor/psyao/velox/src/MediaLibrary.php:24`, `:31-35`).
  - Images are refused above 40 megapixels.
- Files are served by Apache through the `public/storage` symlink created by `storage:link` on each deploy (`scripts/deploy.sh:37`). The symlink is protected from rsync `--delete` (`.github/workflows/deploy.yml:171`).

## 10. What works around Velox (site code that exists because of a Velox gap or behaviour)

| Workaround | Where | Reason / status |
| --- | --- | --- |
| CSP middleware appended to the whole `web` group, then filtered by route name `velox.entry` / `velox.preview` | `app/Http/Middleware/SetSecurityHeaders.php:25-30`, `bootstrap/app.php:26` | Velox serves pages from its own route, so there is no site route group to attach to. Accepted; Velox documents the recipe (friction 8, `VELOX-MIGRATION.md:37`) |
| `Home::render()` returns a custom view and queries the non-public types itself (`listed()`) | `app/ContentTypes/Home.php:66-91` | A one-page site: the Project and SkillGroup collections have no URLs and are pulled into the Home Page |
| Project card reads `($project->origin ?? $project)->slug` | `resources/views/partials/project-card.blade.php:4` | Velox slugs are per Locale by design. Accepted, no Velox change (friction 11, `VELOX-MIGRATION.md:40`) |
| Error shell re-derives the Locale from the URL segment | `resources/views/errors/_shell.blade.php:8-17` | Velox sets the Locale only on its own pages; errors outside them would render in French |
| Static `public/robots.txt` | `public/robots.txt:1-4` | Infomaniak serves its own default `robots.txt` when the file is missing, before the request reaches Laravel/Velox. Velox docs gap psyao/velox#348 (friction 22, `VELOX-MIGRATION.md:51`) |
| `og:locale` / `og:locale:alternate` and Person JSON-LD in the layout | `resources/views/layouts/app.blade.php:7`, `:80-83`, `:88-101` | Kept next to `<x-velox::seo />`, which does not emit them (`VELOX-MIGRATION.md:17`) |
| Inline-Markdown helper for Text/Textarea fields | `app/Support/Content.php:20-31` | Velox fields have no inline-Markdown rendering. Carried over from the pre-Velox site |
| Content move migration fills blank English values with the French ones | `database/migrations/2026_10_19_000000_move_content_into_velox.php:123-137` | "Velox has no per-Field fallback" |
| Move migration carries each former row's dates | `database/migrations/2026_10_20_000000_restore_the_moved_entries_dates.php` | Saving through Velox stamped `now()`. Velox docs gap psyao/velox#350 (friction 23, `VELOX-MIGRATION.md:52`) |
| `php artisan velox:paths` run once by hand in production | `VELOX-MIGRATION.md:50` | The Locales fingerprint is only written by `velox:paths`, so a migrated multilingual site warns. Velox gap psyao/velox#347 (friction 21) |
| `COMPOSER_AUTH` piped on stdin to `deploy.sh` | `scripts/deploy.sh:15-27`, `.github/workflows/deploy.yml:191-200` | Velox is a private repo. Velox's Infomaniak deploy docs stored the token on the shared host. Docs gap psyao/velox#349 (friction 24, `VELOX-MIGRATION.md:53`) |
| `velox:fields:check` added to the deploy | `scripts/deploy.sh:39` | Fails the deploy on stored content the code can't read. Velox's deploy docs skipped it (psyao/velox#349) |
| `DatabaseRestorer` re-asserts the admin row after migrate | `app/Support/DatabaseRestorer.php:26-47` | A pre-move dump lacks Velox's `role`/MFA columns. Site's own (friction 17, `VELOX-MIGRATION.md:46`). Backup stays site code; media not dumped (friction 18) |
| Seeder `forceFill(['role' => …])`, factory users default to Developer | `VELOX-MIGRATION.md:35` | `role` is not mass-assignable. Documented in Velox 0.24 (friction 6) |
| Contact chain uses `defer()` plus an HTTP cron instead of queues/scheduler | `app/Livewire/ContactForm.php:14-22`, `app/Http/Controllers/CronController.php:12-20` | Host constraint (no worker, no crontab, `proc_open` disabled), not a Velox gap |

Velox gaps found on this site and fixed in Velox: frictions 3, 4, 5 (the catch-all became a `fallback()` route; a site may no longer declare `Route::fallback()`), 7, 12, 13 (`titleLabel()`, now used at `app/ContentTypes/Project.php:28-31`) and 15 (`VELOX-MIGRATION.md:32-44`).

## 11. Tests (overview)

- Feature suites (in-memory SQLite, `php artisan test`):
  - `tests/Feature/HomePageTest.php`: rendering, i18n, SEO head, sitemap, robots, ordering, drafts, tags, palette data.
  - `ContactFormTest`, `ContactMessageMailTest`, `ContactMessageNotifierTest`, `NotifyContactMessagesTest`, `KChatNotificationTest`, `CronControllerTest`, `GenerateCronTokenTest`, `ErrorPagesTest`, `StatusbarTest`, `MoveContentIntoVeloxTest`.
  - Admin tests under `tests/Feature/Admin/` (navigation, inbox, widgets, backup, home page, projects/skills, Velox accounts).
  - Unit tests under `tests/Unit/`.
- The test count was 160 at step 11 (`VELOX-MIGRATION.md:19`).
- Browser suite: §8.4.
