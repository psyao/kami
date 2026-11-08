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

## State, failure and concurrency

Each defect M7's ritual found had passed its ticket's review on one of these (#441, #408).

- **History is stored.** A fact about the past ("was ever public", "once sent") lives in a column or a file, never derived from current state a later write can change. List the writes that change its inputs (reschedule, unpublish, rename) and look for a test of that sequence.
- **A write can fail.** On shared hosting a storage, cache or file write meets a read-only directory or a full quota. The diff says what the next request sees: no cache, never a new key or a new version per request.
- **Two requests at once.** Costly work or a shared-file write started from a request is serialised (`Cache::lock()` as in `Conversion`, or `flock()` on a file under `storage/` as in `Sitemap`), and the diff says what the waiting request serves.
- **A path around the kernel.** Code that runs before Laravel boots, or outside a middleware group, lists the middleware it skips (maintenance mode, authentication, security headers) and handles each.

## Tests

- **Reach beyond the diff.** Grep the tests that read what the diff changes, outside the diff too: a Workbench view feeds the `Browser` suite, which pre-push does not run (#412).
- **Query counts go up for a reason.** A raised `expectsDatabaseQueryCount()` names the query added; the reviewer checks it is needed, not that the number matches (#405).
- **Debug tests stay out.** A throwaway test lives in the scratchpad and runs by path, never in `tests/Kami`, where a commit can take it along.
- **A test's default connection stays put.** Under `RefreshDatabase`, switching `database.default` mid-test leaves the transaction open on MySQL, MariaDB and PostgreSQL, and the next test waits on its lock until CI's timeout (#307). SQLite hides it. Test an empty or other database in a test of its own, before anything writes.
- **CI-only engines.** Pre-push runs SQLite; a change to SQL, locking, transactions or schema is reviewed for the three other engines, where CI alone runs it.
