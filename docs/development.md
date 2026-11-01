# Development

Working on Kami itself. `composer.json` scripts and `lefthook.yml` hold the everyday commands.

## Harness

Kami is tested inside [Testbench](https://packages.tools/testbench): a Laravel skeleton in `vendor/orchestra/`, booted with Kami's provider through package discovery, as on a real site. The Workbench (`workbench/`, wired by `testbench.yaml`) adds what a site would: an `AdminPanelProvider` carrying `KamiPlugin`, and a `config/kami.php` with two Sites and two Locales, so no test assumes a single one. Tests run with the host's time zone set to `Europe/Zurich`, as on Infomaniak, and Eloquent in strict mode.

`composer install` also links every Boost skill found in `vendor/` and Kami's own (`resources/boost/skills/`) into `.claude/skills/`, git-ignored, for a coding agent working on Kami.

## Workbench Site

```bash
composer serve
```

Rebuilds the database from nothing, so switching branches is safe, seeds it through `workbench/database/seeders/DatabaseSeeder.php`, publishes Filament's assets and serves the Site at <http://localhost:8000>, the front included. `vendor/bin/testbench serve --host=localhost` serves it again and keeps the database; `composer build` only rebuilds.

| Account | Password | Role |
| --- | --- | --- |
| `admin@example.test` | `password` | `administrator` |
| `editor@example.test` | `password` | `editor`: may view, create and update Entries, not publish them |

The seeder writes through `SaveEntry` (ADR 0011) about ten Entries, one per state: Published in both Locales, Published in `fr` and Draft in `en`, Scheduled, Draft, a blank `en` Field showing Fallback, an untranslatable `Label`, a `Project` with its tabs, conditions, Repeater and Group. The database is a SQLite file in Testbench's skeleton, which no test suite uses: the suites take `testing` from `phpunit.xml.dist`, or the engine from CI's environment.

## Suites

| Suite | Directory | Boots | Runs |
| --- | --- | --- | --- |
| `Kami` | `tests/Kami/` | Testbench with the Workbench | `composer test`, pre-push, every CI job but Browser and Smoke |
| `Install` | `tests/Install/` | Testbench without the Workbench, so no panel carries Kami until a test adds it | `composer test`, pre-push, the coverage step |
| `Browser` | `tests/Browser/` | The Workbench in Chromium through Playwright | CI; locally as `CLAUDE.md` says |
| `Volume` | `tests/Volume/` | The Workbench on a large generated dataset | Nightly (`volume.yml`) |

`tests/Pest.php` maps each directory to its test case: a new suite directory needs a line there and in `phpunit.xml.dist`. `composer test:coverage` needs pcov or Xdebug and fails under 90 % of `src/` outside `Admin`.

## Git hooks

`composer install` installs lefthook's hooks once the checkout is a Git repository; gitleaks comes from Homebrew (`brew install gitleaks`).

- **pre-commit**, on staged files, in order: gitleaks, then Rector on `config/`, `src/` and `workbench/app/`, then Pint. Rector and Pint stage what they fix.
- **pre-push**, on the whole project: `composer analyse` (Larastan at level `max`, no baseline), then `composer test`.

## CI

`tests.yml` runs on every pull request, each job independent of the others:

| Job | Database | Checks |
| --- | --- | --- |
| Lowest | MariaDB 10.11 | `Kami` suite at the PHP floor with the lowest dependencies, under `tests/infomaniak.ini` (Infomaniak's web `disable_functions` and limits), serial |
| Static | SQLite | Pint, Larastan, Rector, gitleaks on the whole history, `composer audit`, zizmor on the workflows, the [licences](#licences) of runtime dependencies, the [archive's contents](#distribution-archive), the [reference and breaking-change gate](#public-surface), coverage of the `Kami` and `Install` suites |
| PostgreSQL 15 | PostgreSQL | `Kami` suite |
| MySQL 8.4 | MySQL | `Kami` suite |
| Browser | SQLite | `Browser` suite in Chromium |
| Smoke | MariaDB 10.11 | The [shared-hosting smoke](#shared-hosting-smoke) |

Static's steps all run once the setup succeeded, so one push reports every failure. No step retries a failing test. `volume.yml` runs the `Volume` suite nightly on MariaDB, and `release.yml` runs [release-please](#releases) on every push to `main`.

### Licences

Every runtime dependency, direct or not, must offer one of MIT, BSD-2-Clause, BSD-3-Clause, Apache-2.0 or ISC (ADR 0034). Another licence fails the Static job until an ADR admits it and the step's list gains it. Development dependencies do not ship and are not checked.

### Distribution archive

Composer installs Kami from `git archive`, which leaves out what `.gitattributes` marks `export-ignore`: tests, the Workbench, CI, tool configs and the design material. It ships the runtime, `docs/guide/`, `docs/reference/`, `docs/adr/`, and `pint.json` and `rector-set.php`, which the starter kit extends from `vendor/`. The Static job compares the archive's top-level entries with the list in its step, so a new top-level file fails CI until it is either export-ignored or added there. To see the archive of your last commit:

```bash
git archive HEAD | tar -t
```

## Public surface

`tests/Kami/PublicSurfaceTest.php` writes one sorted line for everything a site can call or name (ADR 0010) and compares it with its snapshot, `tests/.pest/snapshots/Kami/PublicSurfaceTest/it_snapshots_the_public_surface.snap`:

- classes outside `Internal`: kind, modifiers, parent and interfaces, then the members they declare themselves, with full signatures: public methods, properties and constants (protected ones too on a class that is not `final`), enum cases. A member marked `@internal` is left out;
- `kami:*` commands, read from Kami's command classes rather than from Artisan, with their arguments and options;
- the default keys of `config/kami.php`.

`x-kami::` components, `kami.*` Permissions and `kami_` table names have no collector until the first one exists: a guard test fails the day Kami registers a `kami` view or component namespace, defines a `kami.*` ability on the Gate, or adds a migration. The components and Permissions pages say "Nothing yet".

The same reflection generates `docs/reference/` (`api.md`, `commands.md`, `components.md`, `config.md`, `events.md`, `permissions.md`): signatures plus the first line of each PHPDoc or description. Event classes, in a `Psyao\Kami\<Module>\Events` namespace, go to `events.md` rather than `api.md`.

After a change to the public surface:

```bash
vendor/bin/pest tests/Kami/PublicSurfaceTest.php --update-snapshots
```

```bash
composer reference
```

then review both diffs and commit them with the change. `composer reference` runs the same test with `KAMI_REFERENCE=write`; without it, the test fails when a committed page is stale.

The Static job checks both:

- **Reference** runs `composer reference` and fails if `docs/reference/` then differs from the commit.
- **Breaking changes** fails when the pull request removes or changes a snapshot line, unless its title is marked `!` or one of its commits carries a `BREAKING CHANGE:` footer. Added lines pass. Those are the two places release-please reads after the squash merge: a `!` on a commit's subject alone is lost. Editing the title does not rerun the job: push again.

## Shared-hosting smoke

The CI's Smoke job installs a site from [`psyao/kami-starter`](https://github.com/psyao/kami-starter) with your checkout of Kami on a simulated Infomaniak host, then checks it over HTTP ([test strategy](https://github.com/psyao/kami/issues/29#issuecomment-6425423526), decision 4). It takes the starter kit's `main` from Packagist, as `laravel new` does, so a starter kit change that breaks Kami fails the next pull request here. To run it locally, with Docker running:

```bash
tests/Smoke/run.sh
```

After the install it builds the front assets, as you would on your machine before copying `public/build/`, runs the starter kit's `DemoSeeder`, and publishes a Page in both Locales through `SaveEntry` from the command line. It then requests the admin's sign-in page, `/smoke` and `/en/smoke`, the demo's Singleton at `/` and `/en`, and its Page's relation at `/about`, after `php artisan optimize`: each must answer 200 with its Locale's text and `Content-Security-Policy`, and without `Set-Cookie`. It fails when a check does not answer as expected or when the PHP error log or `storage/logs/` holds anything. The site stays up at <http://localhost:8080> afterwards (`SMOKE_PORT` picks another port); stop it with:

```bash
docker compose -f tests/Smoke/compose.yaml down -v
```

The setup lives in `tests/Smoke/`:

| File | Role |
| --- | --- |
| `compose.yaml` | Apache 2.4, PHP-FPM 8.5, MariaDB 10.11, plus a `cli` service standing for SSH and a `node` one for your machine. No cron, no queue worker |
| `Dockerfile` | PHP with the host's extensions: GD with WebP and without AVIF, Imagick on ImageMagick 6.9.11 |
| `fpm.conf` | The host's web-only settings on top of `tests/infomaniak.ini`: 60 s `max_execution_time`, OPcache off, `display_errors` on, the 120 s wall-clock kill |
| `httpd.conf` | Apache in front of FPM, honouring the site's `.htaccess` |
| `install.sh` | Creates the site with the checkout as a `path` repository, then runs `kami:install` |
| `run.sh` | Starts the stack, runs `install.sh`, builds the assets, seeds the starter kit's demo, publishes the Page, then runs the HTTP checks and the log check |

Each milestone adds its subsystem's checks to `run.sh`.

Known gaps with the real host (`docs/velox-retrospective.md` § 5.1):

- ImageMagick is the host's 6.9.11-60, but built on Debian 12 where the host runs Debian 11: its delegates, AVIF and HEIC among them, may differ. AVIF through Imagick stays a manual probe on the host.
- The database runs next to PHP; the host's is remote, so every query there pays network latency.

## Uncached TTFB

The uncached budget is 200 ms (`docs/architecture.md`, "Cache and performance budget"). Rendering milestones before M7 measure it on staging, on a typical page, with this loop; `kami:bench` replaces it in M7. No page cache exists before M7, so every request is uncached. The staging site is Digest-protected, with its credentials in a `netrc` file (`machine staging.example.com login … password …`, mode 600):

```bash
for i in $(seq 1 21); do
  curl -s -o /dev/null --digest --netrc-file ~/.ttfb-netrc \
    -w '%{http_code} %{time_appconnect} %{time_starttransfer}\n' \
    https://staging.example.com/a-propos
done | tail -n +2 \
  | awk '$1 == 200 { print ($3 - $2) * 1000 }' \
  | sort -n \
  | awk '{ t[NR] = $1 } END { printf "%d runs, median %.0f ms, p95 %.0f ms, max %.0f ms\n", NR, t[int((NR + 1) / 2)], t[int(NR * 0.95 + 0.999)], t[NR] }'
```

It drops the first request as a warm-up and keeps the 200 responses: fewer than 20 runs means some requests failed. The time is the server's, `time_starttransfer - time_appconnect`. The budget holds when the median is at most 200 ms; p95 and max are for information.

`--digest` makes two exchanges per run, a 401 then the 200, and curl adds their times, so the figure overstates the TTFB. It also includes the round trip from your machine, which `kami:bench` will not, since it calls the site from the host. Before a miss becomes a `friction` issue, measure it again without the Digest protection.

## Releases

[release-please](https://github.com/googleapis/release-please) keeps a release pull request open on `main`, with the next version and its `CHANGELOG.md` entry, from the Conventional Commit titles of squash-merged pull requests. Merging it is the release: it tags `v*` and publishes the GitHub Release, which Packagist picks up. Its settings sit in `release-please-config.json`; `.release-please-manifest.json` holds the last released version.

- In 0.x, a `!` commit bumps the minor and `feat` or `fix` bump the patch. A breaking change adds its section to `UPGRADING.md` in the pull request that makes it.
- GitHub holds `tests.yml` on the release pull request: its run reads `action_required` until a maintainer clicks **Approve and run** on the pull request. Merge it once the six jobs are green. A `docs:`, `ci:` or `test:` commit leaves the changelog as it was, so release-please does not refresh the pull request for it; the merge still releases what `main` holds.
- `v*` tags are immutable: a ruleset blocks their update and deletion. A wrong release is fixed by the next one.
