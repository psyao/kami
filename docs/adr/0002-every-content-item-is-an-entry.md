# Every content item is an Entry, in all its Locales at once

Every piece of content, whatever its Content Type, has one row in a common `kami_entries` envelope: `id`, `site`, `type`, `parent_id`, `position`, `created_by`, `updated_by` and timestamps. Routing, Menus, References, revisions, trash and the sitemap all target that single table through real foreign keys. Terms, Singletons and Global Sets are Entries too, so there is no `terms`, `globals` or `settings` table; the Site's editable settings are a Global Set the package declares. One Entry is one content item in all its Locales: a Translation is the part of an Entry that belongs to one Locale, never an Entry of its own. Where field values are stored (JSON, typed columns, or a table per type joined to the envelope) is decided in "Content storage"; how per-Locale state is stored is decided in "Multilingual content and fallback".

This departs from Velox on three structural points:

- Terms now live in the same envelope instead of a table of their own (Velox ADR 0005). Velox added fields and SEO to Terms two weeks after ruling them out (ADR 0018): a Term needs everything an Entry has.
- Global Sets are Entries instead of a separate `velox_globals` table.
- A Translation is no longer an Entry with an `origin_id` (Velox ADR 0013). With that design, every Menu item, Reference and Redirect had to pick one row out of several. Shared values were copied and synced, and some were lost when a field stopped being translatable (Velox ADR 0016).

## Considered options

- **One Eloquent model and table per type, with no common table.** This is the most Laravel-native shape (principle 8). Every cross-type concern (URLs, Menus, References, revisions, sitemap, search) then becomes polymorphic, with no foreign key. Rejected for stability (principle 5, which ranks above principle 8). Velox already lost referential integrity on its morph-keyed `holder_id` (ADR 0018).
- **Media as Entries** (Craft's "assets are elements"). A Media item has no URL, status, publication or hierarchy. Media get a table of their own. A picture that needs its own page is an Entry that references a Media item.

## Consequences

- A Taxonomy is a Content Type preset: its Entries are published by default and can be created from a relation field. It has an archive of the Entries that reference it. Terms attach through References, not a dedicated pivot.
- A Singleton is a Content Type limited to one Entry per Site; a Global Set is a Singleton with no URL.
- The host application's own models (a Product, a hike) cannot be Reference or Menu targets without an Entry row. That door is examined in "Kami inside a custom application", not in the MVP.
- Volume: 30,000 Entries and 50,000 Media are guaranteed and measured on shared hosting. The design target is 1,000,000 Entries on a server with no schema change, so nothing on the public read path may scan the whole table. Beyond that is a custom application, out of scope.
