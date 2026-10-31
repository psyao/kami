# A Filament major lands in a Kami major, and Kami depends on no third-party Filament plugin

Filament broke its plugins at v2→v3 and v3→v4, and sites own Filament code that breaks whatever Kami does: the starter kit's `AdminPanelProvider` and `UserResource`, the `EntryForm` and `EntryTable` subclasses (ADR 0007) and their own Resources. So `psyao/kami` requires exactly one Filament major (`filament/filament:^5`), and moving to the next one is a Kami major (`feat!:`). Its breaking changes stay inside `Psyao\Kami\Admin` and the Filament-typed parts of the public API: the core, the front, the local API and imports do not change in that major (ADR 0011). Kami adopts a new Filament major after its first minor release and before bug fixes end for the previous one. A Kami minor may raise the Filament minor floor.

The package depends only on official `filament/*` packages. A site may add any plugin to its panel, without a guarantee from Kami. In return, Kami coexists with them:

- its plugin id is `kami`, and its assets are registered under `psyao/kami`;
- `KamiPlugin` sets nothing panel-wide (theme, font, avatar provider) and binds nothing in the container;
- the Kami admin works with Filament's default stylesheet: it uses Filament components only, and its own CSS is precompiled in `resources/dist/kami.css`, registered with `$panel->assets()`. A site needs neither a custom theme nor Node for Kami's admin.

## Considered options

- **Two Filament majors at once** (`^5|^6`, with shims in `Admin`). This doubles the cost of every admin change for a developer working a few hours a week, and sites must still port their own Filament code.
- **Staying on the old major until third-party plugins catch up.** Kami depends on none, so there is nothing to wait for.
- **`solution-forest/filament-tree` for hierarchical Content Types.** A tree gives editors a better overview than lists of siblings. Velox paid for it with a UI audit of its own (I-268), two "action does nothing" bugs (G26, G47) and a project with GitHub issues disabled. Rejected: stability (principle 5) wins over editing comfort, which no ranked principle covers.

## Consequences

- Hierarchy in the MVP uses Filament's native `reorderable()` table, limited to the siblings of one Parent and reached through a "Children" action and a breadcrumb; a hierarchical type reorders when it is also `$orderable`, the two flags staying separate as in Velox and Payload. The Parent changes through a `Select` in the form, under `kami.entries.{type}.reorder` (ADR 0014). A tree page in V1 is either Kami's own page, built on `reorder` and Parent, or a plugin admitted by an ADR of its own. Neither changes the schema or a Permission.
- The reorderable table stays paginated (`paginatedWhileReordering()`), and a row moves within its page. This weighs Stability against editing comfort: Filament would otherwise load every row of the type, past a shared host's memory limit on a large one. Kami's `ReorderEntries` keeps the places of the rows it is given, so a page or a search stays correct; a friction reopens it. Filament 5.10.2 is the floor because it is the first with the table's keyboard buttons for moving a row: Accessibility outranks a wider range of versions.
- How long the previous Kami major gets fixes is decided in "Release policy and licence". `UPGRADING.md` covers the Kami side of a Filament major, and Filament's `filament/upgrade` script covers the site's code.
- The starter kit ships `tests/ArchTest.php`, which forbids `Filament\` outside `app/Filament` and `app/Providers/Filament`, so a Filament major touches only those two folders in a site, plus the `User` model. The test allows `App\Models\User`, because Filament admits a User to a panel outside the `local` environment only through its `FilamentUser` contract. The test belongs to the site.
- Panel-wide settings Kami needs come from the starter kit's `AdminPanelProvider`. For example, it sets `->defaultAvatarProvider(InitialsAvatarProvider::class)`, because Filament's default, `UiAvatarsProvider`, sends Users' initials to `ui-avatars.com` (principle 3). Filament ships no local provider, so `Psyao\Kami\Admin\InitialsAvatarProvider` is Kami's own: it draws the initials as an SVG `data:` URI. A host panel sets it the same way. `kami:doctor` warns when the panel carrying `KamiPlugin` lacks such a setting.
- Kami's global search provider is set on Kami's panel only, never bound in the container, so another panel of the host keeps Filament's own.
