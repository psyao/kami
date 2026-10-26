# Kami's migrations become append-only at the first production install, and are squashed only in a major, under an Upgrade floor

This covers the `kami_*` migrations shipped by the package, not the ones sites generate with `kami:make-migration` (ADR 0006). Until a release is deployed to production, nobody has data to protect, so migrations are edited in place. From the release the first production site deploys onwards, they are append-only. No squash happens during 0.x. The one exception in 0.x is 1.0 itself, which may squash with the last 0.x as its Upgrade floor. After 1.0, a squash happens only in a major, with the last minor of the previous major as its floor. `kami:doctor` checks the floor, so `kami:install` stops the deploy of a site that skips it.

Velox squashed its migrations three times in 0.x (its ADR 0012: floors 0.11, 0.15 and 0.18). Each squash was a breaking release with an "update to 0.N first" step, kept the same file names, and failed silently at `migrate` (nothing to run), then loudly on the first page. Kami's three reference sites run in production on 0.x before 1.0, which is reached only once all three do ("Release policy and licence", #30), so the same churn would break all three every time.

## Considered options

- **Squash freely during 0.x** (Velox ADR 0012). The history stays readable, but every squash breaks every production site. Rejected for stability (principle 5).
- **Never squash.** This is the most stable option, and a fresh install replays every migration, which stays fast. But after years the history no longer reads as the schema, and a major is the moment sites already expect upgrade steps. Rejected for simplicity (principle 6): one floor per major costs less than an unbounded history.
- **A floor that is only documented in `UPGRADING.md`.** This is the Velox path, where a skipped step went unnoticed until the first page failed.

## Consequences

- A squash release names its floor in `CHANGELOG.md` and `UPGRADING.md`, and is a `feat!:` commit. Before 1.0, `!` bumps the minor; at 1.0 and after, it bumps the major.
- `kami:doctor` knows the name of the newest migration of the floor version. An install that has already recorded `kami_` migrations but lacks that one is below the floor, and the blocking check fails with "update to <floor> first". A fresh install passes.
- New columns ship in a migration of their own, additive and non-destructive (brief, principle 5). A data move goes through a migration, never through `kami:install` code.
