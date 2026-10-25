# Sites are declared in code, and site-scoped rows carry a site handle

The data model is multisite from the first migration, even though one installation serves one Site in the MVP. This is a departure from Velox, whose `docs/updating.md` ruled multisite out and keyed no table by site. The brief makes multisite a V1 feature that must ship without a heavy migration.

A Site and a Locale are orthogonal: a Site has one or more Locales and a default Locale. Kami does not model a language as a Site, as Craft and Statamic do. Sites are declared in `config/kami.php`, each with its URL (from `.env`), Locales, default Locale and display time zone. Rows carry a `site` string column holding the Site's handle (`default` in the MVP), with no foreign key. Content Types are referenced the same way, by handle.

The rows that carry `site`:
- `kami_entries`, so Terms, Singletons and Global Sets too;
- `kami_media` and `kami_media_folders`;
- Menu items, Redirects, any URL table, and form submissions.

Every uniqueness constraint includes `site`. Translations, References and revisions inherit the site of their parent row and carry no column. Menus are declared in code with no table, so Menu items carry `site` themselves (ADR 0025). Users, roles and the audit log are per installation. In the MVP, an Entry references, parents and lists only content from its own Site.

## Considered options

- **A `kami_sites` table with foreign keys.** This gives integrity in the database. But a Site's URL differs per environment and ends up in `.env` anyway, and an admin screen for Sites is the kind of option screen the brief rules out. Choosing the code declaration trades stability (principle 5) for simplicity (principle 6). The gap is covered by `kami:doctor`, which reports rows whose `site` handle is not declared.

## Consequences

- Turning multisite on (V1) means declaring a second Site and adding a resolver for the current Site. The schema does not change.
- Dates are stored in UTC in `datetime` columns, never `timestamp` (Velox friction S2). Kami forces UTC itself rather than trusting the host's `date.timezone`. The display time zone is a Site attribute, not a global setting as in Velox ADR 0017.
