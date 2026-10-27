# Development

Working on Kami itself. `composer.json` scripts and `lefthook.yml` hold the everyday commands.

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
