# Field values live in a table per Content Type, one row per scope

Each Content Type that declares at least one stored Field gets its own table, `kami_type_<handle>`, joined to the `kami_entries` envelope (ADR 0002) by `entry_id`. Each declared Field is a typed column, so the database knows its type, can index it, and sorts and filters it without JSON functions. A table holds one row per scope, keyed uniquely by `(entry_id, locale)`. The row with `locale = '*'` carries the Shared fields. One row per Locale (`'fr'`, `'en'`) carries the Translatable fields. In each row, the columns outside its scope stay null.

The schema follows the code through ordinary Laravel migrations. Losing a value then always takes a migration someone wrote and reviewed (psyao/velox#283, #284). Content model drift is checked by comparing the declarations against the schema, at a constant cost whatever the volume.

This departs from Velox ADR 0002 (Field values in a JSON `data` column). The brief requires filtering and sorting at 1,000,000 Entries on three engines. With JSON, that needs a second, synchronised index, and every migration or drift check scans every row.

## Considered options

- **A JSON `data` column on the envelope** (Velox ADR 0002, Statamic, Craft 5). Adding a Field needs no migration. Filterable Fields would need an EAV index table kept in sync on save. Renames and removals would be PHP batch rewrites, and the drift check would have to scan rows. Velox also hit MySQL key reordering (A15, D8), and its `JSON_SET` migrations excluded PostgreSQL (§2.9). Rejected for stability (principle 5).
- **JSON plus generated columns.** `virtualAs()`/`storedAs()` take an engine-specific expression, and PostgreSQL before 18 has no virtual columns. This breaks the portability requirement.
- **Full EAV.** About 15 million rows at the design target, reads that must pivot rows into fields, and no typing.
- **Two tables per type, one for Shared fields and one for Translatable fields.** Switching a Field between the two would move a column between tables. Rejected for simplicity (principle 6): with one table, the switch is a data operation and needs no schema change.
- **Shared fields stored on the default Locale's row.** Changing a Site's default Locale would silently orphan them. `'*'` is a sentinel rather than `NULL` because a unique index lets several `NULL`s through on all three engines.

## Consequences

- **Table names.** A type handle is snake_case, 40 characters at most, checked at boot, so `kami_type_<handle>` stays within the identifier limits of PostgreSQL (63) and MySQL (64). A type with no stored Field has no table.
- **Columns.**
  - Each Field type class declares its Laravel column type (`string`, `text`, `integer`, `decimal`, `boolean`, `date`, `dateTime`, `json`).
  - Relation and Media Fields have no column: they live in `kami_references` (ADR 0005).
  - Every Field column is nullable and has no database default. A draft may be incomplete, so "required" is validated in PHP at publication. The default comes from the declaration, so changing it needs no migration.
  - A column is indexed only when its Field is declared filterable or sortable.
- **Structured values** (repeaters, rich text, blocks, option lists) are opaque `json` documents. SQL never queries them by JSON path, and PHP always compares them decoded, never as strings (Velox A15, D8). A value that must be filtered becomes a top-level scalar Field.
- **Universal values.** Values that every type has, and that cross-type subsystems read (title, slug, status, `published_at`, SEO meta), are core columns, never Fields in `kami_type_*`. Their per-Locale table is decided in "Multilingual content and fallback", SEO meta in "SEO".
- **Scope rule.** The write layer in PHP enforces that a Shared field stays null in Locale rows, and the reverse. The database does not enforce it.
- **Locale rows.** Adding a Locale to a Site creates no rows: a missing Locale row reads as an empty Translation, and its fallback is decided in "Multilingual content and fallback". A row whose Locale is no longer declared is kept. `kami:doctor` reports it without failing the deploy.
- **Loading.** Field values always load with the Entry, never lazily: strict lazy loading from day one (Velox I-286). A collection of one type costs one extra query. A collection of mixed types costs one query per type present.
- **`kami:make:migration {type?}`** diffs the declarations against the schema and writes an ordinary migration into the site's `database/migrations`. When a type loses one Field and gains another, it asks in a terminal whether this is a rename. Without a terminal, it refuses rather than guess. It changes a column's type only between `string` and `text`, which hold each other's values. Any other change it refuses, printing a migration to finish by hand: a value that does not convert has no portable answer, PostgreSQL refuses most of these casts outright, and MySQL and MariaDB accept them whenever the rows happen to convert. This trades simplicity (principle 6) for stability (principle 5). Types the package declares, such as the Site settings Global Set, ship their migrations in the package.
- **`Kami\Schema\ContentMigrator`** provides the data operations a schema change cannot express:
  - `renameType($from, $to)`;
  - `renameField($type, $from, $to)`, which renames the column for a stored Field and rewrites `kami_references.field` for a relation Field;
  - `makeTranslatable($type, $field)`, which copies the `'*'` value into each Locale row (creating missing rows) and then clears it;
  - `makeShared($type, $field, keep: <locale>)`, which throws when another Locale holds a different non-null value, unless `discardOthers: true` is passed;
  - `dropType($type)` and `dropLocale($locale)`.

  The operations run in batches through the portable query builder, with no JSON functions, no model events and no `updated_at` change. They have no `down()`: going back means a new migration with the inverse operation. `makeShared` undoes `makeTranslatable` without loss as long as the Locales still agree, and the rename operations undo themselves with the arguments swapped. The drop operations destroy, by design.
- **Content model drift.** `kami:install` fails the deploy in any of these cases:
  - a declared Field has no column;
  - a column belongs to no declared Field;
  - a column has the wrong type;
  - a `kami_type_*` table belongs to an undeclared type.

  Removing a Field from code therefore destroys nothing: the deploy blocks until a migration drops the column or the Field comes back. `kami:doctor` also reports Entries whose `type` is not declared.
- **Shared hosting and server.** Migrations run from the CLI (`kami:install` in the deploy), never from a web request, so web time limits do not apply. MariaDB adds columns instantly. SQLite rebuilds the table for column changes, which Laravel 11+ handles natively. Nothing differs between shared hosting and a server.
- **Cost.** Every change to a Field takes a migration, and each Content Type adds one table. That is the price of typed, indexable columns and loss that is always explicit. It trades simplicity (principle 6) for stability (principle 5).
