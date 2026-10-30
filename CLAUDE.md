# Kami

Laravel + Filament CMS, successor to Velox (`~/Code/velox`). `docs/brief.md` is the source of truth for context, constraints, principles and scope; read it before any design decision. `docs/comparatif-cms.md` is the feature reference (MVP / V1 / V2). Both are Steve's French sources: read them, never translate or rewrite them.

## Design

- `docs/architecture.md`: key decisions, domain model, execution modes, dependencies, risks. Read it before working on a subsystem; the ADR it links holds the reasons.
- `docs/roadmap.md`: the milestones to the MVP. A ticket belongs to one milestone and stays within its scope.
- A changed decision lands as a new or amended ADR, with `docs/architecture.md` updated in the same pull request.

## Commands

From M1, `composer.json` scripts and `lefthook.yml` are the reference. What they do not say:

- Pre-push runs `phpstan analyse`, `pest --parallel` and the `Install` suite; the other engines, the `Browser` suite and the shared-hosting smoke run in CI only.
- `Browser` suite locally: `npm ci`, `npx playwright install chromium`, then `mkdir -p tests/Browser/Traces` (until pest-plugin-browser's next release) and `pest --testsuite=Browser`.
- An intended public API change: refresh `tests/Kami/PublicSurfaceTest.php` with `pest --update-snapshots`, then `composer reference`. A removed snapshot line makes the commit breaking (`!`).
- Coverage needs a driver Herd's PHP lacks: `composer test:coverage` locally, 90 % of the core checked in CI.
- Never skip the hooks (`--no-verify`): they are the only check before a push.
- `composer serve` runs the Workbench Site with its synthetic seeder.

## Languages

- Conversation with Steve: French.
- Everything written to the repo: English (docs, ADRs, code, identifiers, comments, commits, issues, error messages).
- UI strings go through Laravel translation files: English source, French first translation.
- Artisan output stays in English, as Laravel's and Filament's do: UI means the admin and the front.

## Principles, ranked

Security → Privacy → Data sovereignty → Accessibility → Stability → Simplicity → Performance → Laravel-native. The higher one wins a conflict; any decision that trades one against another names both in its ADR. Definitions in `docs/brief.md` § Principes directeurs.

## Shared hosting is first-class

Every subsystem must run fully on Infomaniak shared hosting (no persistent process, no real cron, no Redis, file/database cache without tags, variable PHP extensions, tight memory and time limits). When designing a subsystem, state its behaviour on shared hosting and on a server. Visible behaviour never waits on the scheduler: scheduled publishing is evaluated at read time. Details in `docs/brief.md` § Contraintes techniques.

## Anti-goals

A core that covers the basics natively (SEO, redirects, simple forms, multilingual) with extensions that add rather than repair; conventions plus code over option screens; nothing essential behind a remote service; a small, finished scope over a wide, half-built one.

## Method

- Process follows Matt Pocock's skills (see Agent skills below). User-invoked skills (`/wayfinder`, `/grill-with-docs`, `/to-spec`, `/to-tickets`, `/mattpocock-skills:implement`, `/setup-matt-pocock-skills`): name the command for Steve to run. Name every skill in full (`/mattpocock-skills:retro`): gstack installs a `/retro` of its own.
- The design path and current phase are in `docs/prompt-claude-code.md`.
- Starting or running a ticket's session: `docs/agents/ticket-session.md`.
- Project state lives in the repo and the tracker (research notes, map, ADRs, glossary, roadmap), so any session can resume after a long pause.
- Ask blocking questions one at a time, each with a default recommendation. Stop after every deliverable that needs validation.
- Be concrete: class, table and Artisan command names.
- Velox is evidence, not a default: cite it as evidence (question, evidence, alternatives); a decision that departs from it on a structural point says so in one sentence with the reason.
- For implementation work, load the `ponytail:ponytail` skill and keep it active. Before opening a ticket's pull request, run `/ponytail:ponytail-review` on the branch diff and apply what holds; the milestone ritual runs `/ponytail:ponytail-debt` on `main`.

## Code conventions

- Typed PHP, Pest tests, Larastan, Rector, Pint.
- Database-agnostic: SQLite, MySQL/MariaDB, PostgreSQL. Engine-specific SQL sits behind an abstraction.
- Core (domain, content, front rendering) independent of Filament; Filament serves the admin only.
- Before writing or changing Filament code (resources, pages, schemas, tables, actions, panel config) or its tests, load the `filament-development` skill.
- Narrow public API: internals marked `@internal`, kept apart from public classes.
- A deliberate shortcut carries a `ponytail:` comment naming its ceiling and the ticket, milestone or V1 that lifts it; that ticket removes it.
- Every third-party package justified: need, alternatives, licence, maintenance, shared-hosting compatibility.
- Artisan commands: `kami:<verb>` for the lifecycle, `kami:<area>:<verb>` for an area's tasks, `kami:make:<thing>` for generators. Generators create new files only and print the lines a site adds elsewhere.

## Documentation

- `docs/guide/` is the site developers' documentation and ships in the package. A change to public behaviour updates its guide in the same pull request.
- `docs/reference/` is generated by `composer reference` from the public surface: regenerate it, CI checks it is fresh.
- The design material (`docs/*.md`, `docs/research/`) stays out of the package through `.gitattributes` `export-ignore`; `docs/adr/` ships.

## Commits

Conventional Commits (`feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `ci:`, `chore:`). Breaking changes take `!` (`feat!:`) and a `BREAKING CHANGE:` footer: the type decides the next semver bump.

## Agent skills

### Issue tracker

Issues and specs live in GitHub Issues on `psyao/kami`, via the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

Default vocabulary: `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`, plus `friction` for a problem met on the playground or while migrating a site. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: `GLOSSARY.md` and `docs/adr/` at the repo root. See `docs/agents/domain.md`.
