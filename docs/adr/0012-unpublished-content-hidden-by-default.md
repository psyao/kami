# Unpublished content is hidden from reads by default

The read side of Kami's local API is its public Eloquent models and their scopes (`Entry::query()->ofType('post')->inLocale('fr')`). A site developer who writes that query in a controller must not leak drafts or Entries scheduled for later. So the `Entry` model carries a global scope: it keeps only published Entries whose `published_at <= now()`, evaluated at read time as the brief requires. Code that needs everything lifts it explicitly with `->withUnpublished()`: the admin, preview and the core actions.

## Considered options

- **Everything visible, with an opt-in `->published()` scope.** This is the plain Eloquent default, but one forgotten scope leaks a draft or an embargoed page. Rejected for security (principle 1) over simplicity (principle 6).

## Consequences

- Every admin query and every action lifts the scope. Forgetting it there shows too little, never too much, and the tests of the write path cover it.
- What "published" means (status, revisions, per-Locale publication) is decided in "Drafts, revisions, scheduled publishing, preview". The current-Site constraint will join the same default when multisite arrives (V1), so the door stays open without a change of API.
