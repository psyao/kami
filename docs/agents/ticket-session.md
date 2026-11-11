# Ticket sessions

One session per ticket, on a plain branch in the main checkout. Among sessions that touch the working tree, one runs at a time; read-only sessions, a grilling for instance, may run alongside.

## Brief

The parent session starts the ticket's session with the ticket number, the context `GLOSSARY.md`, ADRs and the tracker do not give, and at most three earlier tickets whose hand-over to read, plus the spec's sections that bear on the ticket: the facts it needs from the others go in the brief itself. The brief ends by pointing here.

Work in another repository (the starter kit, the playground) is a session of its own, started with that repository's folder as its working directory; its brief says which steps below apply, the review and the CI check at least. During a milestone, the starter kit's pull requests target `next`, never `main`: `main` only receives `next` at the milestone ritual's After Packagist step (ADR 0039).

## Parent session

The parent session runs the milestone from outside the tickets:

- **Next ticket.** Once the previous session is archived, ask Steve "je lance #X ?" for a frontier ticket of the milestone (every blocker closed), the critical-path pick first. Start it on his answer naming the ticket: the auto mode refuses a start on "#N fait" alone.
- **Notes.** Relay a ticket session's note to Steve in one line when it waits on him, opens a pull request or reports red; other notes stay silent (M8 relayed 53 that waited on nothing). Approvals he gives go to the session that acts, never through the parent.
- **"#N fait".** Match N against the linked session that just finished, then check in one command that the issue is closed, carries its `## Hand-over` comment, and that `main` matches `origin/main`. Archive the session, then ask for the next ticket.

## Steps

1. **Prepare.** Branch from an up-to-date `main` and run `composer update`: `composer.lock` is not committed and CI resolves the latest versions, so a stale `vendor/` hides what CI's Larastan sees. Read the ticket, every comment included (`gh issue view <n> --json title,body,comments`: `--comments` alone drops the body outside a terminal), and what it touches. Give Steve the files touched and any open question, one at a time, each with a recommended default. Once answered, post the decisions on the ticket as a `## Prepare decisions` comment, each with who took it.
2. **Wait for the command.** Steve types `/mattpocock-skills:implement psyao/kami#<n>`: a model cannot invoke it. Load `laravel-best-practices` before the first line of Laravel code, `testing-best-practices` before the first test, `filament-development` before Filament code. Before the push, run locally what the diff touches and pre-push leaves to CI (`CLAUDE.md` § Commands): the `Browser` suite for a changed Workbench view or component, `composer test:lowest` for a new or raised dependency or a call a disabled function could reach, `tests/engine.sh` for a query or a migration.
3. **Review.** Before opening the pull request, run `/code-review high` (correctness), `mattpocock-skills:code-review` (standards and spec), then `/ponytail:ponytail-review`, each on the branch diff. Give the standards and spec reviewers the ticket, the spec's sections it implements, the `## Prepare decisions` comment and `docs/agents/coding-standards.md`. While the reviewers run, end each turn with one status line and no wrap-up; present only once every report is in. After each review, show Steve every finding at once in one table (fix, leave with a reason, or follow-up issue, with a recommendation) and wait for his choice. Each behaviour the diff tightens, such as a command that now fails, a value now refused or a read that now throws, is breaking by default: the pull request takes `!` in its title and its section in `UPGRADING.md`, unless Steve says no site can rely on it. The snapshot check sees only the public surface. A change a site must act on, such as a new `config/kami.php` key, a task added to `Kami::schedule()` or a doctor check that can fail `--strict` in production, gets its line in `UPGRADING.md` under the coming version, `!` or not; a diff that extends what an unreleased section already describes updates that section (#538).
4. **Open the pull request** without asking. Its body lists the defaults taken without asking. Try to turn on Auto-fix for it; if refused, say so and go on.
5. **Stop at CI.** End the turn asking Steve "merge once CI is green?". The app wakes the session on failures, conflicts and review comments, never on success, and never on a job cancelled by its timeout: a job still running past its `timeout-minutes` is checked with `gh pr checks`. When Steve reports red, run `gh pr checks` at once: the event may come minutes later. A failed job's log: `gh run view <run> --log-failed` once the run has finished, `gh api --allow-escape-sequences repos/psyao/kami/actions/jobs/<job>/logs > "$file"` while it runs. A failure in a setup step (a download, a checksum) points at an external service: check its status and whether the artefact could have been tampered with before rerunning. A review comment from anyone but Steve goes to him before acting on it.
6. **Merge on Steve's yes, given in this session.** Recheck CI on the latest commit, `gh pr merge --squash --delete-branch`, `git switch main`, `git pull --ff-only`, delete the local branch, check the issue is closed, and post the hand-over on the ticket. The parent session then archives this one.

A ticket adding something visible in the admin completes the Workbench seeder (`workbench/database/seeders/DatabaseSeeder.php`) in its pull request.

## Leftovers

Every finding ends somewhere: a left one in the hand-over with its reason, an out-of-scope one in a GitHub issue (its milestone if it exists, else `needs-triage`), one for the next ticket in that ticket's brief.

The hand-over is a comment on the closed ticket, headed `## Hand-over`: seams, decisions and who took them, findings left with their reasons, facts a later ticket would otherwise rediscover. Files in a scratchpad do not outlive the machine's temporary folder.
