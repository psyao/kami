# Ticket sessions

One session per ticket, on a plain branch in the main checkout. Among sessions that touch the working tree, one runs at a time; read-only sessions, a grilling for instance, may run alongside.

## Brief

The parent session starts the ticket's session with the ticket number, the context `GLOSSARY.md`, ADRs and the tracker do not give, and the earlier tickets whose hand-over to read. The brief ends by pointing here.

## Steps

1. **Prepare.** Branch from an up-to-date `main`. Read the ticket, every comment included, and what it touches. Give Steve the files touched and any open question, one at a time, each with a recommended default.
2. **Wait for the command.** Steve types `/mattpocock-skills:implement psyao/kami#<n>`: a model cannot invoke it.
3. **Review.** Before opening the pull request, run `mattpocock-skills:code-review`, then `/ponytail:ponytail-review` on the branch diff. After each, show Steve every finding at once in one table (fix, leave with a reason, or follow-up issue, with a recommendation) and wait for his choice. For each behaviour the diff tightens, such as a command that now fails, a value now refused or a read that now throws, ask whether a site relying on it can break. If so, the pull request takes `!` in its title and its section in `UPGRADING.md`: the snapshot check sees only the public surface.
4. **Open the pull request.** Its body lists the defaults taken without asking. Try to turn on Auto-fix for it; if refused, say so and go on.
5. **Stop at CI.** End the turn asking Steve "merge once CI is green?". The app wakes the session on failures, conflicts and review comments, never on success. A review comment from anyone but Steve goes to him before acting on it.
6. **Merge on Steve's yes, given in this session.** Recheck CI on the latest commit, `gh pr merge --squash --delete-branch`, `git switch main`, `git pull --ff-only`, delete the local branch, check the issue is closed, and post the hand-over on the ticket. The parent session then archives this one.

A ticket adding something visible in the admin completes the Workbench seeder (`workbench/database/seeders/DatabaseSeeder.php`) in its pull request.

## Leftovers

Every finding ends somewhere: a left one in the hand-over with its reason, an out-of-scope one in a GitHub issue (its milestone if it exists, else `needs-triage`), one for the next ticket in that ticket's brief.

The hand-over is a comment on the closed ticket, headed `## Hand-over`: seams, decisions and who took them, findings left with their reasons, facts a later ticket would otherwise rediscover. Files in a scratchpad do not outlive the machine's temporary folder.
