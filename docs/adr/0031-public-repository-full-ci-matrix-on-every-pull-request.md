# The repository is public from the first code commit, so CI runs the full matrix on every pull request

The brief requires CI on every supported engine (SQLite, MySQL/MariaDB, PostgreSQL), and "Test strategy" adds a shared-hosting simulation and a browser suite. GitHub Actions minutes are capped for private repositories (2,000 a month on Free, 3,000 on Pro) and unlimited for public ones on standard runners. Velox, private, ran one MariaDB job per pull request and still billed about 1,250 minutes in 16 days (2026-10-10 to 2026-10-25, `gh run list`), roughly 2,300 a month. The cost came from the number of runs during intense phases (362 workflow runs on 10-11 October alone), not from the size of the matrix. Kami's matrix has six jobs per pull request (four engines, browser, end-to-end smoke), so a private Kami would have to ration CI.

`psyao/kami` and `psyao/kami-starter` therefore become public with their first commit of application code, before the licence is chosen ("Release policy and licence"). Until then the code is all rights reserved, which publishing does not change. ADR 0001 already ships Kami on public Packagist and rejects a private repository as confidentiality the product does not need, so this only moves the publication date forward.

## Considered options

- **Private until 1.0, with rationed CI.** One SQLite job per push, the full matrix only on `ready_for_review` or a `ci:full` label, engines tested locally through Docker. About 600 to 900 minutes a month. Engine bugs would surface late, on the one run that counts, and Velox's history shows MariaDB-only CI let MySQL and PostgreSQL drift (retrospective §2.9). Rejected for stability (principle 5).
- **A self-hosted runner on Steve's Mac.** Free, but CI runs only while the machine is on, and a self-hosted runner on a repository that later goes public executes code from pull requests on a personal machine. Rejected for security (principle 1).
- **CI through local Git hooks only.** Hooks are skipped by `--no-verify` and by agent sessions on other machines, so they cannot be the gate.

## Consequences

- Every pull request runs the whole matrix with no minute budget: MariaDB 10.11 (PHP floor, lowest dependencies, under `tests/infomaniak.ini`), SQLite (newest PHP and dependencies, plus Pint, Larastan, Rector, coverage, gitleaks, `composer audit`, zizmor), PostgreSQL and MySQL (newest), the `Browser` suite and the shared-hosting end-to-end smoke. The `Volume` suite runs nightly on `main`.
- Secrets must never reach the history: gitleaks runs on every commit (lefthook) and in CI, workflows pin actions by SHA with `persist-credentials: false`, and zizmor checks them.
- Sites' private data never enters either repository. Fixtures are synthetic, and the reference sites' content and URL inventories stay in their own private repositories.
