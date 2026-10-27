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
| Static | SQLite | Pint, Larastan, Rector, gitleaks on the whole history, `composer audit`, zizmor on the workflows, coverage of the `Kami` and `Install` suites |
| PostgreSQL 15 | PostgreSQL | `Kami` suite |
| MySQL 8.4 | MySQL | `Kami` suite |
| Browser | SQLite | `Browser` suite in Chromium |
| Smoke | MariaDB 10.11 | The [shared-hosting smoke](#shared-hosting-smoke) |

Static's steps all run once the setup succeeded, so one push reports every failure. No step retries a failing test. `volume.yml` runs the `Volume` suite nightly on MariaDB.

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
