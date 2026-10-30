# Upgrading

Every breaking release has a section here, 0.x included, newest first: what changed, why, and what a site does about it. A site updates with `composer update psyao/kami`, then deploys as usual ([Deployment](docs/guide/deployment.md)).

- **Versions.** Before 1.0, a breaking release bumps the minor (`0.1.x` to `0.2.0`) and everything else is a patch, so `^0.1` receives every non-breaking change. From 1.0, a breaking release is a major.
- **Public contract.** The public surface (`docs/reference/`), the content events and their properties, the keys of `config/kami.php`, the props of `x-kami::` components, route names and `kami.*` permissions. Anything marked `@internal` or under an `Internal` namespace may change in any release.
- **Deprecations.** A deprecated element carries `@deprecated since x.y, use …` and a line here and in `CHANGELOG.md`. Nothing warns at runtime: Larastan and IDEs flag it. It is removed in the next major, after at least one minor in which it was deprecated.
- **Upgrade floor.** From 1.0, a release that squashes Kami's migrations is a major, and its section names the release a site must reach first.
- **Security fixes.** A patch that tightens a default for security reasons is listed here too, though it breaks the rule above.

## 0.2.0

0.2.0 is the walking skeleton: Content Types, Entries in several Locales, Kami's route, Roles and the security headers. 0.1.0 shipped no migration and no content API, so the breaking change in the release notes (`SaveEntry::handle()` and the edited content migration) touches no 0.1 site. What a 0.1 site adds, before its first `kami:install` on 0.2:

1. **The User model.** It implements `KamiUser` with `HasKamiRoles`; `kami:install` stops until it does. Its `canAccessPanel()` admits Kami Role holders only, unless your panel serves other Users too ([Users and roles](docs/guide/users-and-roles.md#the-user-model)).
2. **The configuration.** `php artisan vendor:publish --tag=kami-config`, then declare your Content Types and Locales in `config/kami.php` ([Content types](docs/guide/content-types.md)).
3. **Kami's route.** `Kami::routes()` at the end of `routes/web.php` ([Routing and rendering](docs/guide/routing-and-rendering.md#kamis-route)).
4. **The security headers.** `SecurityHeaders` appended to the `web` group in `bootstrap/app.php`, and `'kami.security-headers:admin'` at the end of the panel's middleware ([Security](docs/guide/security.md#your-own-pages)).
5. **`storage/framework/kami/.gitignore`.** `kami:doctor` writes `doctor.json` there and Kami does not ignore it for you: add a `.gitignore` holding `*` and `!.gitignore`, as the starter kit does.
6. **The first administrator.** `kami:install --no-interaction` now creates one and fails without `--email`, `--name` and the `KAMI_ADMIN_PASSWORD` environment variable ([The first User](docs/guide/installation.md#the-first-user)). A test or deploy script that runs it on an empty database passes them; the starter kit's `tests/Feature/InstallTest.php` shows how.

Then run `kami:install` as usual: it migrates, syncs Paths and creates the first administrator.
