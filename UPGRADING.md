# Upgrading

Every breaking release has a section here, 0.x included, newest first: what changed, why, and what a site does about it. A site updates with `composer update psyao/kami`, then deploys as usual ([Deployment](docs/guide/deployment.md)).

- **Versions.** Before 1.0, a breaking release bumps the minor (`0.1.x` to `0.2.0`) and everything else is a patch, so `^0.1` receives every non-breaking change. From 1.0, a breaking release is a major.
- **Public contract.** The public surface (`docs/reference/`), the content events and their properties, the keys of `config/kami.php`, the props of `x-kami::` components, route names and `kami.*` permissions. Anything marked `@internal` or under an `Internal` namespace may change in any release.
- **Deprecations.** A deprecated element carries `@deprecated since x.y, use …` and a line here and in `CHANGELOG.md`. Nothing warns at runtime: Larastan and IDEs flag it. It is removed in the next major, after at least one minor in which it was deprecated.
- **Upgrade floor.** A major that squashes Kami's migrations names the release a site must reach first. `kami:install` stops with "update to <floor> first" until it has.
- **Security fixes.** A patch that tightens a default for security reasons is listed here too, though it breaks the rule above.
