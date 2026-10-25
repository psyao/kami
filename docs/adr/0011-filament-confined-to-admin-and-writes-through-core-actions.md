# Filament is confined to the `Admin` namespace, and every write goes through core actions

The brief requires the core (domain, content, front rendering) to stay independent of Filament, which has broken its plugins at most majors. Kami ships as one package (ADR 0001), so the boundary is a namespace, enforced in CI. Everything that touches Filament lives under `Psyao\Kami\Admin`: `KamiPlugin`, the Resources, `$panel->assets()` registration and the Livewire test mixins. A Pest architecture test fails the build when any other namespace uses `Filament\` or `Psyao\Kami\Admin`. That includes `KamiServiceProvider`, which registers the core (config, migrations, views, translations, commands) and never touches Filament.

Every content write (create, update, publish, delete) goes through a core action such as `Psyao\Kami\Content\Actions\SaveEntry`. The action validates, indexes References, keeps revisions and dispatches events. The Filament admin is one client of these actions, alongside the local API, imports and Artisan commands. No business logic lives in a Resource, and the admin never calls `Model::save()` on a Kami model.

The actions trust their caller and do not check authorization. As in Payload's local API, calling Kami from code is trusted like calling Eloquent. Authorization belongs to each boundary: policies in the admin, checks in HTTP controllers. An action takes a `?User $actor` only to record the Creator and Last modifier; it is null for imports and the CLI. Invalid input raises Laravel's `ValidationException`, which Filament displays as is.

## Considered options

- **A separate `psyao/kami-filament` package.** It gives a hard Composer boundary, but ADR 0001 already rejected split repositories for a developer working a few hours a week. The architecture test gives the same guarantee.
- **A convention without a check.** Velox showed that a boundary nobody checks erodes.
- **Writes through Eloquent with observers.** Observers hide the order of side effects, and an import that bypasses them (`saveQuietly`, raw inserts) silently breaks the References index.
- **Authorization inside the actions.** Every import and command would need a fake actor with full rights. Keeping authorization at the boundary trades a safety net in depth (security, principle 1) for one clear rule per boundary (simplicity, principle 6). The risk is a site endpoint that calls an action without checking; the action's documentation states that it does not authorize.

## Consequences

- Absorbing a Filament major touches `Admin` only. The front, the local API and imports keep working while the admin is ported.
- The repository follows the Filament plugin skeleton (`src/`, `config/`, `database/`, `resources/dist`, `stubs/`, `tests/`), except that its single service provider is split in two along this boundary, and it ships no `hasInstallCommand()` (`kami:install`, ADR 0001).
