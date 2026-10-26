# Kami ships as a package plus a starter kit, and is always a Filament plugin

Kami is a product, not a site, and it must also live inside Laravel applications that do other things (agency projects, kawaiidream.net) without taking them over. So the whole product ships as one Composer package, `psyao/kami`. A separate starter kit, `psyao/kami-starter`, gives a new site a ready-wired Laravel application. Both are public on GitHub and Packagist. The licence is decided in "Release policy and licence".

Inside a host application, Kami registers nothing global unless the host opts in. It also never ships a panel of its own: the host adds `KamiPlugin::make()` to its own panel, and the starter kit's `AdminPanelProvider` does just that. A "standalone CMS" is therefore the starter kit, not a second code path. Every Kami table is prefixed `kami_`, so Kami can sit next to a host's own `pages`, `media` or `redirects` tables.

## Considered options

- **Standalone application to fork.** Every site diverges from it, so an update becomes a merge, and Kami cannot be added to an existing application.
- **Package only.** Every site re-does the same wiring by hand: panel provider, `User`, `.htaccess`, error pages, CI build.
- **Monorepo with split repositories.** The tooling costs too much for one developer working a few hours a week.
- **Private VCS repository** (Velox ADR 0001). It puts a Composer token on a shared host used by every site of the account (Velox friction A24). It trades security (principle 1) for confidentiality the product does not need.
- **Package that also ships its own panel.** This means two code paths, plus a possible clash with the host's panel. Rejected for simplicity (principle 6).
- **Configurable table prefix.** Rejected because it is an option screen in disguise: a convention beats a setting.

## Consequences

- The starter kit holds wiring only:
  - `AdminPanelProvider`;
  - a `User` model wired for panel access;
  - the `Kami::routes()` call;
  - a starter theme;
  - an `.htaccess` without the trailing-slash double redirect (P15);
  - error pages;
  - `composer.json` scripts;
  - an example content type;
  - a CI workflow that builds front assets (no Node on the server).
- Once created, the starter kit's code belongs to the site and is never synced back from upstream. The starter kit has no version of its own: it requires `psyao/kami:^x`. Anything existing sites must receive belongs in the package.
- The package auto-registers only migrations, config, views and translations. Public routes (`Kami::routes()`, placed and prefixed by the host), the panel plugin and middleware are explicit calls. Kami never adds a catch-all route on its own, so it never swallows the host's 404s.
- One command, `kami:install`, serves both first install and every later deploy, and there is no `kami:upgrade`. The command is idempotent and safe in CI. In order, it:
  1. runs the blocking `kami:doctor` checks;
  2. runs `migrate --force`;
  3. runs Kami's idempotent seeds;
  4. publishes Filament assets;
  5. fails the deploy, with a non-zero exit code, on content model drift;
  6. clears Kami's caches;
  7. makes sure at least one User holds `kami.users.manage`. When `--email` names an existing User, that User gets the `administrator` Role and keeps their password. An unknown email creates the User. With no terminal and no `--email`, the command fails and prints `kami:user:grant {email} administrator`. This step never creates a second account in a host application whose staff already exist (kawaiidream.net, "Kami inside a custom application").
- `kami:install` never writes to host files (Velox friction A7). In an existing application, it prints the lines the host must add and fails until `KamiPlugin` is registered on a panel.
- First admin credentials (Velox friction I-120):
  - Email and name come from `--email` and `--name`, prompted through `PromptsForMissingInput` in a terminal.
  - The password is never a command argument. In a terminal it is read with Laravel Prompts `password()`. Without a terminal it comes from `KAMI_ADMIN_PASSWORD`; when that is unset, the command prints a one-time link to set the password.
