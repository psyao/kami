# Translations own per-Locale state, and blank Fields fall back to the default Locale

The values every Entry has in each Locale live in one table, `kami_translations`, with one row per Entry and Locale and a unique key on `(entry_id, locale)`. Its columns are `entry_id`, `locale`, `title`, `slug`, `status` and `published_at`, plus timestamps. A Translation exists only when its row exists. The SEO ticket adds its per-Locale meta columns to the same row.

There is no status at Entry level. An Entry is visible in a Locale only through a published Translation in that Locale. Without one, the Entry is absent in that Locale: it has no URL there, it does not appear in listings, and it is left out of hreflang and the language switcher. The slug belongs to the Translation. A site that needs one identifier in every Locale (aguet's project cards, Velox A11) declares a Shared field for it.

A Content Type declared untranslatable has a single Translation with `locale = '*'`, read in every Locale, and all its Fields are Shared. This is the sentinel of ADR 0006, and it covers aguet's Tags (Velox I-320) with no special case elsewhere.

When a Translatable field is blank in a Translation that exists, reads take the value from the Site's default Locale.

- **Blank means `null`.** The write layer stores `''` and `[]` as `null`.
- **One hop.** Fallback always goes to the default Locale, never through a chain.
- **Relation fields too.** Fallback also applies to Translatable relation fields (`kami_references.locale`).
- **Never core columns.** It never applies to title or slug: both are required at publication.
- **Only published content.** Fallback reads the default Locale's Translation only when that Translation is visible to a public read. A draft or unpublished default Translation yields `null`, so unreviewed text never leaks into a published page.
- **Value plus Locale.** A read returns the value together with the Locale it came from. Kami's rendering components add a `lang` attribute when that Locale differs from the page's (WCAG 3.1.2, Language of Parts).

This departs from Velox ADR 0013, where a Translation was an Entry row with an `origin_id` and there was no per-Field fallback (ADR 0002 already removed the row-per-Locale Entry).

## Considered options

- **Core per-Locale columns on the Locale rows of `kami_type_<handle>`.** Routing, the sitemap and mixed-type listings would have to query one table per type, and a type with no stored Field has no table (ADR 0006). Rejected for simplicity (principle 6).
- **An Entry shown in every Locale, with fallback content when its Translation is missing,** as a per-type option. It would serve French pages under `/en/` URLs, and it would need an option screen in code for a case none of the three reference sites has. Field-level fallback already covers a partly translated Entry.
- **A configurable fallback chain per Locale** (`de → fr → en`). Nothing needs it yet. A per-Locale `fallback` key in the Site declaration can be added later without breaking anything.
- **Fallback regardless of the default Translation's status.** Simpler, but a draft's text would appear on a published page. Rejected for security (principle 1) over simplicity (principle 6).

## Consequences

- **Adding a Locale** (German, Italian) means declaring it in `config/kami.php`. It creates no rows and needs no migration. Visitors see nothing in that Locale until Translations are published there, so there is no "hidden Locale" flag. `ContentMigrator::dropLocale` also deletes the Locale's `kami_translations` rows, and `kami:doctor` reports Translations of an undeclared Locale.
- **Changing the default Locale** changes where fallback reads from. Shared values sit in `'*'` and do not move.
- **No per-Field opt-out of fallback in the MVP.** An optional Field left blank on purpose in one Locale (a note meant only for French readers) shows the default Locale's value. A flag on the Field declaration can be added later without breaking anything.
- **Media.** A Media item's alt text and caption are per Locale and follow the same fallback. The file, focal point and credit are shared.
- **Doors for V1.** A per-Locale translation workflow ("needs translation", "outdated") can compare each Translation's `updated_at` with the default Locale's. Copying an Entry to another Locale is an action over the same rows. Admin-edited interface strings stay a V1 database loader that overlays `lang/`.
- **Shared hosting and server.** Nothing differs between them. Fallback adds no query: the Entry's Translations and type rows load together (ADR 0006, Loading).
