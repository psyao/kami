# Internals live in `Internal` namespaces, and the public surface is snapshotted

From 1.0, Kami promises strict SemVer (brief, principle 5), so what a site may rely on must be narrow and visible in the code itself. Every module keeps its internals in an `Internal` sub-namespace (`Psyao\Kami\Content\Internal\…`). A class there is internal and carries `@internal`. Every class outside an `Internal` namespace is public, with its public methods; a single public method that is not meant for sites is marked `@internal` on the method.

Classes are `final` by default. Only a closed list of extension points is left open; that list is owned by "Extension points: events, registries, overrides". `tests/Kami/PublicSurfaceTest.php` snapshots every public class, method and signature, so any change to the surface shows up in the diff and in review.

The database schema is internal. Sites read and write through the public models and actions, never through the tables. Only the `kami_` table names (ADR 0001) and the documented attributes and relations of the public models are covered by SemVer. Migrations stay non-destructive as the brief requires, but their shape is not an API.

This departs from Velox ADR 0015, where the public surface was "what `docs/` names" and nothing in the code told a public class from an internal one: an audit at 0.x found 98 classes, many of them public by accident.

## Considered options

- **The documentation as the list** (Velox ADR 0015). The code gives no signal, and docs and code drift apart.
- **`@api` tags.** A second list to keep in step with the code; Velox rejected it for the same reason.
- **A public database schema.** Sites could write raw SQL, but every column change would become a breaking change, and "Content storage" would be frozen at its first shape. Rejected for stability (principle 5).

## Consequences

- An architecture test fails when a class under `Internal` lacks `@internal`, or when a public signature outside `Internal` names an internal type.
- Opening a class for extension later is not breaking. Closing one is, which is why `final` is the default.
- Documentation of the public API can be generated from the namespaces, without a hand-kept list.
