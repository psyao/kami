# Kami

Laravel + Filament CMS, successor to Velox (`~/Code/velox`). `docs/brief.md` is the source of truth for context, constraints, principles and scope; read it before any design decision. `docs/comparatif-cms.md` is the feature reference (MVP / V1 / V2). Both are Steve's French sources: read them, never translate or rewrite them.

## Languages

- Conversation with Steve: French.
- Everything written to the repo: English (docs, ADRs, code, identifiers, comments, commits, issues, error messages).
- UI strings go through Laravel translation files: English source, French first translation.

## Principles, ranked

Security → Privacy → Data sovereignty → Accessibility → Stability → Simplicity → Performance → Laravel-native. The higher one wins a conflict; any decision that trades one against another names both in its ADR. Definitions in `docs/brief.md` § Principes directeurs.

## Shared hosting is first-class

Every subsystem must run fully on Infomaniak shared hosting (no persistent process, no real cron, no Redis, file/database cache without tags, variable PHP extensions, tight memory and time limits). When designing a subsystem, state its behaviour on shared hosting and on a server. Visible behaviour never waits on the scheduler: scheduled publishing is evaluated at read time. Details in `docs/brief.md` § Contraintes techniques.

## Anti-goals

A core that covers the basics natively (SEO, redirects, simple forms, multilingual) with extensions that add rather than repair; conventions plus code over option screens; nothing essential behind a remote service; a small, finished scope over a wide, half-built one.

## Method

- Process follows Matt Pocock's skills (see Agent skills below). User-invoked skills (`/wayfinder`, `/grill-with-docs`, `/to-spec`, `/to-tickets`, `/setup-matt-pocock-skills`): name the command for Steve to run.
- The design path and current phase are in `docs/prompt-claude-code.md`.
- Project state lives in the repo and the tracker (research notes, map, ADRs, glossary, roadmap), so any session can resume after a long pause.
- Ask blocking questions one at a time, each with a default recommendation. Stop after every deliverable that needs validation.
- Be concrete: class, table and Artisan command names.
- Departing from Velox on a structural point: say so in one sentence with the reason.
- No application code until the architecture and the first spec are validated.

## Code conventions

- Typed PHP, Pest tests, Larastan, Rector, Pint.
- Database-agnostic: SQLite, MySQL/MariaDB, PostgreSQL. Engine-specific SQL sits behind an abstraction.
- Core (domain, content, front rendering) independent of Filament; Filament serves the admin only.
- Narrow public API: internals marked `@internal`, kept apart from public classes.
- Every third-party package justified: need, alternatives, licence, maintenance, shared-hosting compatibility.

## Commits

Conventional Commits (`feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `ci:`, `chore:`). Breaking changes take `!` (`feat!:`) and a `BREAKING CHANGE:` footer: the type decides the next semver bump.

## Agent skills

### Issue tracker

Issues and specs live in GitHub Issues on `psyao/kami`, via the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

Default vocabulary: `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: `GLOSSARY.md` and `docs/adr/` at the repo root. See `docs/agents/domain.md`.
