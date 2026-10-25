# Kami tables use ULID primary keys

Every `kami_` table keys its rows with a ULID (`HasUlids`, `char(26)`) rather than an auto-increment integer, and has no second public UUID column. One identifier then serves as the internal link target, the import and export key, and the id in admin and preview URLs. It survives a move between databases, which the replayable imports and later content sharing between Sites rely on. It cannot be enumerated, a security gain (principle 1), and it sorts by creation time, so InnoDB inserts stay append-mostly. It is portable to SQLite, MySQL/MariaDB and PostgreSQL. Users are the exception: they belong to the host application, so Kami's user foreign keys take whatever key type the host's User model uses.

## Considered options

- **Integer primary key plus a public `uuid` column** (Craft's `uid`). This means two identifiers to keep in step in every link, import and URL.
- **UUID v4.** Random values fragment the clustered index on MySQL/MariaDB.

## Consequences

- Indexes are wider than with integers. This is negligible up to the 1,000,000-Entry design target, and it trades a little performance (principle 7) for security and stability (principles 1 and 5).
