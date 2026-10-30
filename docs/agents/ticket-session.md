# Ticket sessions

One session per ticket, on a plain branch in the main checkout. Among sessions that touch the working tree, one runs at a time; read-only sessions, a grilling for instance, may run alongside.

## Brief

The parent session starts the ticket's session with the ticket number, the context `GLOSSARY.md`, ADRs and the tracker do not give, and the hand-over notes folder. The brief ends by pointing here.

## Steps

1. **Prepare.** Branch from an up-to-date `main`. Read the ticket, every comment included, and what it touches. Give Steve the files touched and any open question, one at a time, each with a recommended default.
2. **Wait for the command.** Steve types `/mattpocock-skills:implement psyao/kami#<n>`: a model cannot invoke it.
3. **Review.** Before opening the pull request, run `mattpocock-skills:code-review`, then `/ponytail:ponytail-review` on the branch diff. After each, show Steve every finding at once in one table (fix, leave with a reason, or follow-up issue, with a recommendation) and wait for his choice.
4. **Open the pull request.** Its body lists the defaults taken without asking. Try to turn on Auto-fix for it; if refused, say so and go on.
5. **Stop at CI.** End the turn asking Steve "merge once CI is green?". The app wakes the session on failures, conflicts and review comments, never on success. A review comment from anyone but Steve goes to him before acting on it.
6. **Merge on Steve's yes, given in this session.** Recheck CI on the latest commit, `gh pr merge --squash --delete-branch`, `git switch main`, `git pull --ff-only`, delete the local branch, check the issue is closed. The parent session then archives this one.

A ticket adding something visible in the admin completes the Workbench seeder (`workbench/database/seeders/DatabaseSeeder.php`) in its pull request.

## Leftovers

Every finding ends somewhere: a left one in the hand-over note with its reason, an out-of-scope one in a GitHub issue (its milestone if it exists, else `needs-triage`), one for the next ticket in that ticket's brief. Hand-over notes live outside the repository, so what a later milestone needs goes to the tracker.
