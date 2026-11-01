# Milestone ritual

`/to-tickets` makes the ritual the milestone's last ticket, `ready-for-human`, blocked by every other one. What it must meet is in `docs/roadmap.md` § Acceptance criteria for every milestone; these are the steps, in order.

1. **Review.** `/code-review high` on `<last tag>...HEAD` for `src` and `database`. Reproduce what can be, on the engines concerned. Defects to fix before the release go in one ticket; the others go to `needs-triage` with a target.
2. **Version.** Read `docs/development.md` § Releases, and name the number the milestone's changes call for. If it differs from the release pull request's, a `Release-As:` footer sets it, with its `UPGRADING.md` section when it is a minor.
3. **Debt.** `/ponytail:ponytail-debt`, then `/ponytail:ponytail-audit`, on `main`. Each audit finding kept goes where step 1's do: the fixes ticket if it should hold the release, else `needs-triage` with a target.
4. **Playground.** Steve tries the features from the guides alone, after `dev-main` and `kami:install`, with `kami:doctor --strict` clean. An agent runs these trials only when he asks, and the ticket then says so with what it left untried. Each problem is a `friction` issue, kept in the milestone only if it should hold the release.
5. **Triage.** Each `needs-triage` issue opened during the milestone has a target in a comment.
6. **Release.** Steve clicks **Approve and run** on the release pull request. After a `docs:`, `ci:` or `test:` commit, `gh pr update-branch` brings its CI up to date with `main`. Merge once the six jobs are green.
7. **After Packagist.** The starter kit and the playground leave `dev-main` for the new version, and `laravel new --using=psyao/kami-starter` is checked to install it. Close the ritual, the spec and the Milestone last, each once what it tracks is done.
