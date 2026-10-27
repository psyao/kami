# Development

Working on Kami itself. `composer.json` scripts and `lefthook.yml` hold the everyday commands.

## Harness

Kami is tested inside [Testbench](https://packages.tools/testbench): a Laravel skeleton in `vendor/orchestra/`, booted with Kami's provider through package discovery, as on a real site. The Workbench (`workbench/`, wired by `testbench.yaml`) adds what a site would: an `AdminPanelProvider` carrying `KamiPlugin`, and a `config/kami.php` with two Sites and two Locales, so no test assumes a single one. Tests run with the host's time zone set to `Europe/Zurich`, as on Infomaniak, and Eloquent in strict mode.

`composer install` also links every Boost skill found in `vendor/` and Kami's own (`resources/boost/skills/`) into `.claude/skills/`, git-ignored, for a coding agent working on Kami.

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

The CI's Smoke job installs a site from [`psyao/kami-starter`](https://github.com/psyao/kami-starter) with your checkout of Kami on a simulated Infomaniak host, then checks it over HTTP ([test strategy](https://github.com/psyao/kami/issues/29#issuecomment-6425423526), decision 4). To run it locally, with Docker running:

```bash
tests/Smoke/run.sh
```

It fails when a check does not answer as expected or when the PHP error log or `storage/logs/` holds anything. The site stays up at <http://localhost:8080> afterwards (`SMOKE_PORT` picks another port); stop it with:

```bash
docker compose -f tests/Smoke/compose.yaml down -v
```

The setup lives in `tests/Smoke/`:

| File | Role |
| --- | --- |
| `compose.yaml` | Apache 2.4, PHP-FPM 8.5, MariaDB 10.11, plus a `cli` service standing for SSH. No cron, no queue worker |
| `Dockerfile` | PHP with the host's extensions: GD with WebP and without AVIF, Imagick on ImageMagick 6.9.11 |
| `fpm.conf` | The host's web-only settings on top of `tests/infomaniak.ini`: 60 s `max_execution_time`, OPcache off, `display_errors` on, the 120 s wall-clock kill |
| `httpd.conf` | Apache in front of FPM, honouring the site's `.htaccess` |
| `install.sh` | Creates the site with the checkout as a `path` repository, then runs `kami:install` |
| `run.sh` | Starts the stack, runs `install.sh`, the HTTP checks and the log check |

Each milestone adds its subsystem's checks to `run.sh`.

Known gaps with the real host (`docs/velox-retrospective.md` § 5.1):

- ImageMagick is the host's 6.9.11-60, but built on Debian 12 where the host runs Debian 11: its delegates, AVIF and HEIC among them, may differ. AVIF through Imagick stays a manual probe on the host.
- The database runs next to PHP; the host's is remote, so every query there pays network latency.

## Releases

[release-please](https://github.com/googleapis/release-please) keeps a release pull request open on `main`, with the next version and its `CHANGELOG.md` entry, from the Conventional Commit titles of squash-merged pull requests. Merging it is the release: it tags `v*` and publishes the GitHub Release, which Packagist picks up. Its settings sit in `release-please-config.json`; `.release-please-manifest.json` holds the last released version.

- In 0.x, a `!` commit bumps the minor and `feat` or `fix` bump the patch. A breaking change adds its section to `UPGRADING.md` in the pull request that makes it.
- The release pull request is opened with the workflow's token, so `tests.yml` does not run on it: it only changes `CHANGELOG.md` and the manifest.
- `v*` tags are immutable: a ruleset blocks their update and deletion. A wrong release is fixed by the next one.
