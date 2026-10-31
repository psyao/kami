# One References table with two real foreign keys, no morph

Every Reference lives in one table, `kami_references`:

- `source_entry_id`;
- `field`;
- `locale` (null when the Reference is shared across Locales);
- `target_entry_id`;
- `target_media_id`;
- `position`.

Exactly one target column is set, which a portable CHECK enforces. Terms, Singletons and Global Sets are Entries (ADR 0002), so a Reference only ever targets an Entry or a Media item. Two nullable foreign keys therefore cover every case, and the database keeps referential integrity. A `target_type`/`target_id` morph pair would lose it, as Velox's `holder_id` did (Velox ADR 0018).

The table is the source of truth for relation fields, such as Terms, Bylines and related Entries, and it loads with eager loading and no N+1. Internal links and Media inside rich text are indexed into the same table when the Entry is saved. Their rows are rebuilt per field and per Locale. The table is what answers "used by", warns before a deletion and keeps links valid when an Entry is renamed.

## Consequences

- Hard deletion cascades: References to or from a deleted Entry or Media item disappear, and the field reads them as absent. The editor is warned before the deletion, through the reverse lookup. A Parent with children cannot be hard-deleted (`RESTRICT`), because silently moving children up would change their URLs. A deleted User sets `created_by` and `updated_by` to null.
- `target_media_id` has no foreign key until `kami_media` exists: M5 adds it, with its table. Nothing writes the column before then.
- The table has a ULID `id` beyond the columns above, as every `kami_` table does (ADR 0003), and some hosts set MySQL's `sql_require_primary_key`.
- References are plain queries inside a request or `SaveEntry`'s transaction, so they behave the same on shared hosting and on a server.
- A new kind of target needs a new nullable foreign-key column. That is acceptable, because the targets are expected to stay Entries and Media.
