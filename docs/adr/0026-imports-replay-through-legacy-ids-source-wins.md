# Imports replay through legacy ids: the source wins, unchanged items are skipped by checksum

psyao.ch and steve-et-aline.ch move from WordPress, which stays live until each site switches over. Their imports therefore have to run again and again against fresh dumps, and they must survive interruption: sea's full run takes 466 s, so it runs from the CLI. Velox's importers could not do either. They refused to run on a non-empty database, ran everything in one transaction, kept no legacy id, and left uploaded files behind on rollback (Velox P11). Kami makes an Import replayable by recording legacy ids and letting the source win.

- **Legacy ids** live in one table, `kami_import_keys`, rather than in a column on each table: `site`, `source`, `legacy_id`, a nullable `entry_id` FK and a nullable `media_id` FK (exactly one set, both `CASCADE`), `checksum` and `seen_at`, unique on `(site, source, legacy_id)`. `source` names a system and its id space, such as `wordpress.post`, `wordpress.term` and `wordpress.attachment`. Only Entries (Terms included) and Media get a key. A Redirect replays by its natural key through `SaveRedirect`, and a Menu replays by rebuilding its tree under its handle.
- **The source wins.** A replay rewrites an Entry or Media item from the source and overwrites any change made by hand in Kami. Until switch-over, fixes belong in the importer, never in the admin.
- **The checksum is a hash of what the importer hands to Kami**, not of the source row, and for a Media item it covers the file's content. A change to the importer's code therefore rewrites what it affects. An unchanged item causes no write, no event and no `updated_at` change. Resuming an interrupted run means running it again: there is no checkpoint table and no `--resume` option.
- **There is one transaction per source item**, covering the Entry, its Translations, its Fields and its key. The run stops at the first failure and names the source and legacy id. Batches only read the source and bound memory.
- **The public API is one deep class**, `Psyao\Kami\Import\Import`: `start()` opens `AuditContext::batch()`, then come `entry()`, `media()` and `find()`, and `finish()` returns created, updated, unchanged and unseen counts. Original `created_at` and `updated_at` pass only through it; `SaveEntry` does not take them, so the admin cannot backdate an edit. Writes go through `SaveEntry` and `SaveMedia` (ADR 0011), and Content events fire with a null actor (ADR 0029).
- **Kami never deletes on its own.** Items missing from the source are reported as unseen, and the importer decides whether to trash them.

Importers stay site commands (`psyao:import`). Kami ships no `kami:import` runner in the MVP. The generic WordPress importer (V1) is another client of `Import`, and its web mode can spread the same per-item calls over `database` queue jobs drained by Ticks.

## Considered options

- **Skip what already exists, matisse's practice** (an insert-only `legacy_id` column per table). It is simpler, but a fix in WordPress or in the importer never reaches items imported earlier, so every rehearsal would need a fresh database.
- **The source wins unless the item was edited in Kami since the last import.** It detects conflicts that the "fixes go in the importer" rule prevents, at the cost of a second timestamp and a conflict report.
- **Deterministic ULIDs derived from `(source, legacy_id)`**, with no table. Nothing to store, but a recreated Entry would get a new key, and the ULID would stop sorting by creation time (ADR 0003).
- **One transaction per batch.** One bad item would roll back 500 good ones, together with their file writes.

## Consequences

- File writes are cleaned up in `SaveMedia`, not in importers. The action writes an original to a fresh path and deletes it if its transaction rolls back. Replacing a file never overwrites the original in place: the old file is removed after commit. The admin benefits too. Kami runs no automatic sweep of orphan originals: run against the wrong database, it would empty the library. That keeps stability (principle 5) at the cost of some disk left behind by crashes.
- Forward links resolve in a second pass. An importer imports its items, then runs over them again. `find()` now resolves the internal links that were left as raw URLs, so those items change and are rewritten, and all the others are skipped. Reserving ULIDs in advance would break the `kami_references` foreign key.
- An Import is always an Artisan command, never a database migration. Kami's own migrations and `kami:make-migration` stubs use only the query builder, and a test fails if `database/migrations` mentions `Models\` (Velox A9, A10).
- Keys outlive switch-over at no cost and disappear with their Entry or Media item. After switch-over, Kami is the source of truth, and an Import is not replayed.
