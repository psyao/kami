# Sites extend Kami through events after commit, never through filters

A site reacts to content changes through Kami's own events, and changes Kami's behaviour only through the declaration hooks and open classes it already writes. There are no WordPress-style filters (`apply_filters`), and no "before" events (`saving`, `publishing`) that could alter or cancel a write.

The rule is mechanical: **every public core action dispatches exactly one event, named after it in the past tense** (`SaveEntry` → `EntrySaved`, `StoreSubmission` → `SubmissionStored`). Events are `final readonly` classes in `Psyao\Kami\<Module>\Events`. They are dispatched synchronously **after the transaction commits**, so a listener never sees a write that is later rolled back. Each carries the model and the `?User $actor` (null for the CLI and imports). The one exception is `EntryPublished`, which `kami:announce-publications` also dispatches for scheduled publications, late and at most once per Translation and moment (ADR 0019).

Kami's own side effects never listen to these events. Bumping the Content version, rewriting Paths, indexing References and marking the sitemap stale are called directly in the actions, in an order that can be read in the code (ADR 0011). A site that fakes events in a test, or a listener that is never registered, cannot break the page cache. Eloquent's model events on `Entry` are not part of the public API: actions and `ContentMigrator` may write through the query builder (ADR 0006), so whether `saved` fires is an internal detail.

The comparatif lists "hooks / events / filters" (8/10). Velox only offered Eloquent events per subclass, which did not fire for writes that bypassed the model.

## Considered options

- **Filters on values and output** (WordPress, Craft's `EVENT_*` with mutable payloads). Every change to Kami's output would become a possible hook, any number of listeners could rewrite it in an order nobody controls, and each filter's signature would be frozen by SemVer. This is the "plugin hell" the brief rules out. A change to behaviour already has a home: a hook method on the Content Type, Block or Form class, or an open class.
- **Cancellable "before" events.** They would let validation or vetoes live outside the declaration, hidden in listeners, which is the order-of-side-effects problem that made ADR 0011 reject observers.
- **Eloquent model events as the contract.** They would come for free, but they miss raw writes, fire for internal rows, and would freeze how actions write.
- **Kami's side effects as listeners of its own events.** It would be uniform, but the cache would depend on listener registration and `Event::fake()`. Rejected for stability (principle 5).
- **Catching listener exceptions after commit** and only `report()`-ing them. The editor would not see an error for a write that did succeed, but a failed notification would go unnoticed. Kami lets the exception propagate, as Laravel does: a visible failure beats a silent one (stability, principle 5, over editing comfort).

## Consequences

- **Event catalogue (MVP).**
  - Content: `EntrySaved(entry, locale, created, actor)`, `EntryPublished(entry, locale, scheduled, actor)`, `EntryUnpublished`, `TranslationDeleted`, `EntryTrashed`, `EntryRestored`, `EntryDuplicated(source, copy, actor)`, `EntryDeleted`.
  - Media: `MediaSaved`, `MediaDeleted`, from the public actions `SaveMedia` and `DeleteMedia`.
  - Routing: `RedirectSaved`, `RedirectDeleted`. A public `DeleteRedirect` action joins `SaveRedirect`.
  - Menus: `MenuItemSaved`, `MenuItemMoved`, `MenuItemDeleted`.
  - Forms: `SubmissionStored`.
  - Users and Roles get no Kami event: the host owns `users`, and Laravel's auth events already exist. Conversions, Ticks and Scheduled tasks emit nothing, since they are not content writes.
  - A new public action brings its event in the same minor version.
- **Deletion events carry values, not models** (ULID, type handle, `site`, Locale where relevant), so a queued listener never tries to reload a row that is gone.
- **Listeners may be queued** (`ShouldQueue`). On shared hosting they run at the next Tick (ADR 0020); the documentation points to `defer()` for slow work that may be lost.
- **Imports fire events too**, one per action, with a null actor. There is no `Kami::withoutEvents()` in the MVP; adding it later is additive.
- **Registries.** Core declarations live in `config/kami.php` (`types`, `forms`, `menus`); Blocks are offered per Field (`RichText::make()->blocks()`). Admin registrations live on `KamiPlugin::make()` (`fieldComponents()`, `forms()`, `tables()`). There is no programmatic registration API for third-party packages: a package documents the lines a site adds. One can be added later without a break.
- **Overrides.** A type swaps its model with `$model`. `EntryResource` and `TermResource` stay `final` in the MVP, and their class is read from config, which is the door for overridable Resources in V1 (ADR 0010: opening a class later is not breaking). There is no switch to turn off a module: Forms, Menus or Feeds that a site never declares add no route and no navigation item. Container bindings of internal classes are not a contract, and the MVP publishes no interface for sites to rebind.
- **Custom actions on content** use Filament's own Actions, with no Kami abstraction. Row and bulk actions go on an `EntryTable` subclass. `EntryForm::actions(): array` places header actions on the edit page. An `EntryForm` may add components that are not Fields (layout, `Actions`, text); only a field component with no declared Field is an error when the form is built (amends ADR 0007). A custom action that writes calls a core action.
- **Settings and Redirects.** There is no separate settings API. The package's site-settings Global Set, `SiteSettings`, always registered without being listed in `kami.types`, its table and labels shipping with the package, is read with `SiteSettings::entry()` and written with `SaveEntry`, seeders included (closes Velox A16). Redirects go through `SaveRedirect`, `DeleteRedirect` and the readable `Redirect` model (closes Velox P16).
- **SemVer on open classes.** A minor version may add public or protected methods **with a default implementation** to an open class or abstract base. It never adds an abstract method and never changes a signature. On `EntryForm` and `EntryTable`, SemVer covers method names and signatures, not the Filament components that `parent::` returns (Velox ADR 0015, 7df705e). Adding an event, or a property to an event, is minor; removing either is major.
- **The closed list is enforced.** An architecture test fails when a class outside an `Internal` namespace is neither `final` nor abstract, unless it is on the allow-list in `tests/Kami/ArchTest.php`. Adding to that list is a reviewed change to the public API.
- **Shared hosting and server** behave the same: events are synchronous and in-process; only queued listeners wait for a worker or a Tick.
