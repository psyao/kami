# Entries render through a `render()` hook on their Content Type

`Kami::routes()` resolves a request to an Entry (ADR 0008). The Content Type then turns that Entry into a response through one public method, `render(Entry $entry): View|Response`, which Kami calls through the container, so a type gets method injection. By default it returns `view("kami.{handle}", ['entry' => $entry])`. Archives go through `renderArchive(CursorPaginator $entries)` (default view `kami.{handle}-archive`). A Term is the Archive of the Entries that reference it, so its Taxonomy's `render()` receives the Term and gets `$entries` as well. A type that needs more data, such as a Home page listing the latest posts, overrides `render()` and runs its queries there. A site that prefers a controller calls it from `render()`. Since the hook returns any response, Inertia or a Livewire page stays possible without anything from Kami.

The hook never assumes the Entry is saved or visible: preview (Drafts, revisions, scheduled publishing, preview) calls the same method with an unsaved or unpublished Entry. A 404 never reaches it, because resolution has already failed.

This keeps Velox ADR 0008. In Velox, the same hook covered all three reference sites' Home pages with no other seam (retrospective §1.2).

## Considered options

- **A controller per type** (`protected ?string $controller = HomeController::class`). It is closer to plain Laravel (principle 8), but every customised type needs a second file, and the declaration then points at HTTP code anyway. A site that wants one still gets it by calling it from `render()`. Rejected for simplicity (principle 6).
- **View-resolution conventions and view composers only.** There would be no hook to learn. But a composer can only add data to a view: it cannot return a redirect, JSON or an Inertia response, and a Home page's queries would live far from its type. Velox found that every site needed this seam.
- **A template hierarchy** (`View::first()` over type, template and default). The comparatif files it under V1, along with a per-Entry template choice. `render()` can already pick a view from a Field value, so either one can be added later without breaking anything.

## Consequences

- **Views are the site's.** The package ships no page views and no design. `kami:doctor` reports every routable type, Archive or Taxonomy whose conventional view is missing. The starter kit ships the views of its example type.
- **Package components** are anonymous Blade components under `x-kami::`, with semantic, accessible markup (WCAG 2.2 AA) and no design: `image` (ADR 0017), `pagination`, `locales`, `date` and `breadcrumb`. The Menus, SEO and preview tickets add theirs. Their names and props are public API. A site overrides one through Laravel's own view publishing.
- **Front assets.** The package ships no CSS: components expose `kami-*` classes, which the site styles. Its only JS, the click-to-load `Video` facade (ADR 0016), is prebuilt in `resources/dist/front/`, copied to `public/vendor/kami/` by `kami:install`, and emitted once per page by the view that needs it. A default CSP of `script-src 'self'` allows it, with no nonce, so a cached page stays a static file.
- **No public Livewire in what Kami ships.** Kami's components and the starter kit load neither Livewire nor Alpine. A site may still mount Livewire components in its own views.
- **Shared hosting and server** behave the same: rendering is plain Blade in the request, with no process and no queue.
