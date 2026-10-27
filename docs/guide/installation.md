# Installation

## Requirements

Kami runs on PHP 8.5 or later, on the command line and on the web. On shared hosting the two often run different PHP builds, so check both.

PHP extensions:

- **Required:** ctype, dom, fileinfo, filter, hash, iconv, intl, json, libxml, mbstring, openssl, pcre, session, tokenizer, xmlreader, zip, and the PDO driver of your database (`pdo_sqlite`, `pdo_mysql` or `pdo_pgsql`).
- **Recommended:** gd or imagick. Without either, Kami still runs, but cannot resize images.

### Writable directories

The user PHP runs as must be able to write to `storage/` and `bootstrap/cache/`. Kami keeps its own files under `storage/framework/kami/`.

### Database

Kami supports SQLite, MySQL 8.4, MariaDB 10.11 and PostgreSQL 15 or later. The connection comes from the `DB_*` settings in `.env`.

## Checking the environment

```bash
php artisan kami:doctor
```

`kami:doctor` lists every problem it finds with its severity, the fix and the section of this guide that explains it:

- an **error** means the site cannot run: the command exits with 1;
- a **warning** means a doubtful setting: the command exits with 0, unless you pass `--strict`, which fails on warnings too.

Run it after any change to the environment, and with `--strict` in your CI if warnings should fail it. Each run writes its result to `storage/framework/kami/doctor.json`.
