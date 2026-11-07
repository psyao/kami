# Coding standards for review

What a reviewer of a Kami diff weighs beyond `CLAUDE.md`, the ADRs and `docs/agents/ticket-session.md`: the judgement calls no linter makes. Mechanical rules live in Pint, Rector, Larastan, `tests/Kami/ArchTest.php` and `lefthook.yml`, not here.

## Before reporting a finding

- **Verified.** Check each claim against the code, the tests and `vendor/` before reporting it: a test said missing may exist, a query said N+1 may be eager loaded (`Media::$with`), a framework said to misbehave may handle the case. A claim left unchecked is reported as "unverified", with what would settle it.
- **Decided.** The ticket's `## Prepare decisions` comment holds what Steve decided before implementation. A diff that follows a decision is not a finding; a finding against the decision itself is raised as such, naming it.
- **In scope.** The ticket's acceptance criteria and the spec's Out of Scope bound the review. Work another ticket of the milestone owns is named with that ticket, not reported as missing.

## Standing context

- **One Site until V1.** Site scoping of queries, routes and counts waits for #205; its absence is not a finding unless the ticket asks for it.
- **The sibling's shape.** A new action, command or test mirrors the existing one beside it (`SaveEntry` and `SaveMedia`, `DeleteEntry` and `DeleteMedia`, `ContentTypes` validation). Matching it is not duplication to remove; departing from it needs a reason in the diff.
- **`ponytail:` marks a shortcut.** It names a ceiling and the ticket, milestone or V1 that lifts it. A design Steve decided is not a shortcut and carries no marker; a marker naming a closed ticket is a finding.
- **Two runtimes.** A subsystem states its behaviour on shared hosting and on a server; a guide or ADR describing only one is a finding.

## Tests

- **A test's default connection stays put.** Under `RefreshDatabase`, switching `database.default` mid-test leaves the transaction open on MySQL, MariaDB and PostgreSQL, and the next test waits on its lock until CI's timeout (#307). SQLite hides it. Test an empty or other database in a test of its own, before anything writes.
- **CI-only engines.** Pre-push runs SQLite; a change to SQL, locking, transactions or schema is reviewed for the three other engines, where CI alone runs it.
