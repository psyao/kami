# Filament 5 plugin coexistence with a host app, and history of breaking majors

Research note for Kami (issue psyao/kami#5). Question: how a Filament 5 package registers resources and pages in a host application's panel or ships its own panel; how panel access, user models and multiple panels work; how a package ships prebuilt assets with no Node on the server; what the v2→v3, v3→v4 and v4→v5 upgrades broke for plugins; what Filament 5 offers to extend the RichEditor (custom blocks) and the Builder field.

- Facts only, from primary sources. Where a source is silent, this note says **not documented**.
- Retrieved 2026-10-25. Filament docs = `5.x` branch (commit `65c004f`), latest release `v5.10.3` (https://github.com/filamentphp/filament/releases/latest). Older guides read from the `4.x` and `3.x` branches.
- Statements marked **(source)** come from reading code, not prose documentation. Source links point to the `5.x` branch.
- Inputs: `docs/velox-retrospective.md` §1.1 ("Filament churns", A4 panel access), §2.10 (third-party plugins); `docs/research/mutualise.md` §7 (assets on shared hosting).

---

## 1. Plugin model: two kinds of plugin

- Filament distinguishes **panel plugins** (used inside a panel, "or as a complete Panel in and of itself") and **standalone plugins** (form fields, table columns etc. used outside a panel). One package may be both. https://filamentphp.com/docs/5.x/plugins/getting-started#understanding-plugin-types
- A plugin is a Laravel package; Filament recommends `spatie/laravel-package-tools` and the official plugin skeleton (`filamentphp/plugin-skeleton`, configured with `php ./configure.php`). https://filamentphp.com/docs/5.x/plugins/getting-started#creating-a-plugin, https://filamentphp.com/docs/5.x/plugins/panel-plugins#introduction
- The **Plugin object** (`Filament\Contracts\Plugin`) is only for panels; standalone plugins configure everything in their service provider. The Plugin object is optional even for panel plugins. https://filamentphp.com/docs/5.x/plugins/getting-started#the-plugin-object
- Panel plugins "can be added to each panel one at a time, and are also configurable differently per-panel". https://filamentphp.com/docs/5.x/plugins/panel-plugins#introduction

## 2. Registering into the host's existing panel

- `Filament\Contracts\Plugin` has three methods: `getId(): string`, `register(Panel $panel): void`, `boot(Panel $panel): void`. **(source)** https://github.com/filamentphp/filament/blob/5.x/packages/panels/src/Contracts/Plugin.php
  - `getId()`: unique among plugins; "specific enough to not clash".
  - `register()`: may use "any configuration option that is available to the panel", including resources, custom pages, themes, render hooks.
  - `boot()`: "run only when the panel that the plugin is being registered to is actually in-use. It is executed by a middleware class."
  - https://filamentphp.com/docs/5.x/plugins/panel-plugins#configuring-the-panel-with-a-plugin-class
- The host opts in from its panel provider: `$panel->plugin(new BlogPlugin())` or `->plugin(BlogPlugin::make())`, where `make()` returns `app(static::class)` so the container can swap the implementation. https://filamentphp.com/docs/5.x/plugins/panel-plugins#fluently-instantiating-the-plugin-class
- `Panel::plugin()` calls `$plugin->register($this)` **immediately**, then stores the plugin keyed by `getId()`. `getPlugin($id)` throws `LogicException` if the plugin is not registered on that panel; `hasPlugin($id)` checks. **(source)** https://github.com/filamentphp/filament/blob/5.x/packages/panels/src/Panel/Concerns/HasPlugins.php
- Per-panel configuration: setter/getter methods on the plugin object, read inside `register()`; read from elsewhere with `filament('blog')->hasAuthorResource()` or a static `get()` helper returning `filament(app(static::class)->getId())`. https://filamentphp.com/docs/5.x/plugins/panel-plugins#configuring-plugins-per-panel
- Without editing the host's panel provider: a package service provider can call `Panel::configureUsing(fn (Panel $panel) => ...)` in `register()` and attach its plugin to panels by `$panel->getId()`. The docs present this for modules ("allows modules to configure themselves without requiring changes to your panel provider files"). https://filamentphp.com/docs/5.x/advanced/modular-architecture#registering-plugins-conditionally-for-specific-panels
- **Configurable resources and pages** (present in the 4.x and 5.x docs): one resource or page class registered several times in a panel, each with its own key, routes, navigation and slug.
  - Configuration class extends `Filament\Resources\ResourceConfiguration` (pages: `PageConfiguration`); linked via `protected static ?string $configurationClass`.
  - Registered as `OrderResource::make('active')->...`; slug becomes `{base}/{key}` unless `slug()` overrides it.
  - Runtime: `static::getConfiguration()` (null for the default registration), `hasConfiguration()`, `getUrl(..., configuration: 'archived')`, `withConfiguration($key, fn)`.
  - A plugin can accept configurations from the host (`TasksPlugin::make()->taskResources([...])`).
  - https://filamentphp.com/docs/5.x/plugins/configurable-resources-and-pages
- Resource discovery: `discoverResources(in:, for:)` and `discoverPages()` / `discoverClusters()` exist on the panel; the modular-architecture guide uses them with module paths. https://filamentphp.com/docs/5.x/navigation/clusters, https://filamentphp.com/docs/5.x/advanced/modular-architecture
- Strict authorization: by default, if a model policy or policy method is missing, Filament **grants** access; `->strictAuthorization()` makes it throw instead. This is a panel setting, so it is the host's choice, not the plugin's. https://filamentphp.com/docs/5.x/panel-configuration#strict-authorization-mode

## 3. Shipping a whole panel

- A panel's configuration class extends `Filament\PanelProvider`, a standard Laravel service provider. A package can ship one and register it under `extra.laravel.providers` in its `composer.json`. Example sets `->id('blog')->path('blog')`, resources, pages, widgets, `middleware()`, `authMiddleware()`. https://filamentphp.com/docs/5.x/plugins/panel-plugins#distributing-a-panel-in-a-plugin
- An app can have "as many panels as you want, and each can have its own set of resources, pages and widgets"; `php artisan make:filament-panel app` creates `app/Providers/Filament/AppPanelProvider.php`, served at `/app` by default, registered in `bootstrap/providers.php`. https://filamentphp.com/docs/5.x/panel-configuration#introducing-panels, https://filamentphp.com/docs/5.x/panel-configuration#creating-a-new-panel
- Per-panel settings relevant to coexistence: `path()`, `domain()`, `middleware()`, `authMiddleware()`, `assets()`, `authGuard(string $guard)`, `authPasswordBroker()`, `login()`, `registration()`, `passwordReset()`, `emailVerification()`, `profile()`, `viteTheme()`. https://filamentphp.com/docs/5.x/panel-configuration; **(source)** https://github.com/filamentphp/filament/blob/5.x/packages/panels/src/Panel/Concerns/HasAuth.php, https://github.com/filamentphp/filament/blob/5.x/packages/panels/src/Panel/Concerns/HasTheme.php

## 4. Panel access, user models, multiple panels

- Default: "all `App\Models\User`s can access Filament locally". In non-local environments, the user model must implement `Filament\Models\Contracts\FilamentUser` with `canAccessPanel(Panel $panel): bool`. https://filamentphp.com/docs/5.x/users/overview#authorizing-access-to-the-panel
- `canAccessPanel()` receives the panel, so one method decides access to every panel, e.g. by `$panel->getId() === 'admin'`. https://filamentphp.com/docs/5.x/users/overview#authorizing-access-to-the-panel
- Enforcement, `Filament\Http\Middleware\Authenticate`: **(source)** https://github.com/filamentphp/filament/blob/5.x/packages/panels/src/Http/Middleware/Authenticate.php
  - Unauthenticated on the panel's guard (`Filament::auth()`): redirect to the panel login URL.
  - Then `abort_if(...)` with 403 when: the user implements `FilamentUser` and `canAccessPanel($panel)` is `false`; **or** the user does not implement `FilamentUser` and `config('app.env') !== 'local'`.
  - The panel checked is `Filament::getCurrentOrDefaultPanel()`.
  - Consequence for Velox A4 ("panel open to every user when a user model overrides access"): access is exactly what `canAccessPanel()` returns; Filament adds no other gate.
- The contract lives on the **host's** user model. A plugin cannot implement it for the host; a package that ships its own panel is still gated by the host user model's `canAccessPanel()` unless that panel uses another guard via `authGuard()`. https://filamentphp.com/docs/5.x/users/overview; **(source)** HasAuth.php above.
- Other user-model contracts: `HasAvatar` (`getFilamentAvatarUrl()`), `HasName`; default avatars come from the remote service ui-avatars.com, swappable with `defaultAvatarProvider()`. https://filamentphp.com/docs/5.x/users/overview#setting-up-user-avatars
- Resource-level authorization uses model policies. https://filamentphp.com/docs/5.x/resources/overview#authorization

## 5. Prebuilt assets, no Node on the server

- All Filament packages share one asset system: register in a service provider with `FilamentAsset::register([...], package: 'vendor/name')`, then `php artisan filament:assets` copies files **as-is** into `public/`, into a directory named after the package (no clash between plugins). https://filamentphp.com/docs/5.x/advanced/assets#the-filamentasset-facade, https://filamentphp.com/docs/5.x/advanced/assets#registering-assets-for-a-plugin
- Where to register:
  - Assets shared by standalone components (lazy CSS/JS, Alpine components): plugin service provider's `packageBooted()`.
  - Assets that should load on every page of one panel: `$panel->assets([...])` inside the plugin object's `register()`, "to avoid loading panel-specific assets in panels that do not use your plugin".
  - https://filamentphp.com/docs/5.x/plugins/getting-started#registering-assets, https://filamentphp.com/docs/5.x/panel-configuration#registering-assets-for-a-panel
- `filament:assets` does not bundle or resolve `import`s. Bundled JS must be compiled first (Vite for the app, esbuild suggested for async Alpine components). https://filamentphp.com/docs/5.x/advanced/assets#using-vite-compiled-javascript-files
- Packages ship the build output: "include the generated `dist/` directory in your Composer release. Consumers should not need npm dependencies or Vite configuration". Use `Js::make(...)->loadedOnRequest()` to avoid eager loading; `FilamentAsset::getScriptSrc()` includes the Composer package version in the URL; chunk filenames need content hashes. https://filamentphp.com/docs/5.x/advanced/assets#publishing-es-modules-in-plugins
- Async Alpine components: `AlpineComponent::make('id', __DIR__.'/../resources/dist/x.js')`, loaded in Blade with `x-load` + `x-load-src="{{ FilamentAsset::getAlpineComponentSrc('id', 'vendor/pkg') }}"`. https://filamentphp.com/docs/5.x/plugins/building-a-panel-plugin#step-3-setting-up-the-provider, https://filamentphp.com/docs/5.x/advanced/assets#asynchronous-alpinejs-components
- **Tailwind**: builds are per application. Plugin authors "probably should not" ship built Tailwind CSS; the user adds `@source '../../../../vendor/<pkg>/resources/views/**/*'` to their custom theme. If the plugin must work with Filament's default stylesheet (no custom theme), it must register a compiled stylesheet with the styles it needs. https://filamentphp.com/docs/5.x/advanced/assets#using-tailwind-css-in-plugins
- A custom theme (`php artisan make:filament-theme [panel]`) installs Tailwind via npm, writes `resources/css/filament/{panel}/theme.css`, adds it to `vite.config.js` and registers `->viteTheme()`. Build-time Node is required for it. https://filamentphp.com/docs/5.x/styling/overview#creating-a-custom-theme
- Deployment: `filament:install` adds `@php artisan filament:upgrade` to `post-autoload-dump`, which runs `filament:assets` on every Composer autoload dump. Filament's own published assets are git-ignored (`/public/css/filament`, `/public/fonts/filament`, `/public/js/filament`); third-party plugin assets may land elsewhere and need their own ignore rules. https://filamentphp.com/docs/5.x/advanced/assets#ignoring-published-assets-in-version-control
- CSP: scripts rendered through `FilamentAsset` get the nonce from `Vite::useCspNonce()`; the app does not need Vite for this. https://filamentphp.com/docs/5.x/advanced/assets#content-security-policy-nonces

## 6. What each major broke for plugins

### Release timeline and support

| Version | Released | New features | Bug fixes | Security fixes |
|---|---|---|---|---|
| 3.0.0 | 2023-08-01 | ended Aug 1 2024 | until Aug 1 2026 | until Jan 1 2028 |
| 4.0.0 | 2025-08-12 | yes | until Jan 15 2027 | until Jan 15 2028 |
| 5.0.0 | 2026-01-16 | until 6.x stable | ~1 year after 6.x | ~2 years after 6.x |

Dates: `gh release view v3.0.0|v4.0.0|v5.0.0 -R filamentphp/filament`. Support columns: https://filamentphp.com/docs/5.x/introduction/version-support-policy. Policy: bug-fix PRs accepted for the previous major for one year; security fixes planned "for at least two years". Same page.

All three guides carry the same warning: "Some plugins you're using may not be available in vN just yet", with the advice to remove, replace, wait, or send PRs. https://filamentphp.com/docs/3.x/panels/upgrade-guide, https://filamentphp.com/docs/4.x/upgrade-guide, https://filamentphp.com/docs/5.x/upgrade-guide

### v2 → v3 (Livewire 3, Laravel 10+)

From https://filamentphp.com/docs/3.x/panels/upgrade-guide, https://filamentphp.com/docs/3.x/forms/upgrade-guide, https://filamentphp.com/docs/3.x/support/plugins/getting-started#upgrading-existing-plugins:

- **Plugin system replaced**: "a new universal plugin system that breaches the constraints of the admin panel". `PluginServiceProvider` deprecated; plugins extend spatie's `PackageServiceProvider` with a static `$name`.
- **Config file → panel provider**: `config/filament.php` replaced by `app/Providers/Filament/*PanelProvider.php`; configuration done in `Filament::serving()` (theme, navigation, menus) must move. One config file for all Filament packages (`config/forms.php` removed).
- **User model**: `canAccessFilament()` renamed `canAccessPanel(Panel $panel)`.
- **Moved classes**: `Filament\Resources\Form` → `Filament\Forms\Form`; `Filament\Resources\Table` → `Filament\Tables\Table`.
- **Signatures**: many resource/page methods became `public` (navigation, breadcrumbs, headings, `table()`); `getRouteBaseName()` / `getRouteName()` gained `?string $panel`; relation manager `form()` / `table()` no longer static; `$middlewares` → `$routeMiddleware`; `getFormTabLabel()` → `getContentTabLabel()`; `$get`/`$set` typed `Filament\Forms\Get`/`Set`.
- **Front-end**: Heroicons v2 (icon names changed); Blade icon components disabled, custom icons must be registered in a blade-icons set; `@filamentScripts`/`@filamentStyles` replace Livewire's directives outside panels; forms CSS/JS files removed; `TextInput` masks moved to Alpine's mask plugin; `secondary` colour → `gray`.
- **Env**: `FILAMENT_FILESYSTEM_DRIVER` / `FORMS_FILESYSTEM_DRIVER` → `FILAMENT_FILESYSTEM_DISK`.
- Automated script: `filament/upgrade:^3.2`, `vendor/bin/filament-v3`.

### v3 → v4 (Tailwind 4, Laravel 11.28+)

From https://filamentphp.com/docs/4.x/upgrade-guide. The guide says the script "handles many small changes that aren't mentioned in the upgrade guide, but it doesn't handle all breaking changes" (`filament/upgrade:^4.0`, `vendor/bin/filament-v4`, Rector v2, needs PHPStan 2 / Larastan 3). Items affecting plugins and content:

- **Styling (high impact)**: custom themes must move to Tailwind CSS v4 (`tailwind.config.js` no longer used); Filament's Tailwind classes moved into CSS via `@apply`, so classes used in your own views "won't be included in Filament's default stylesheet" without a custom theme.
- **Namespaces**: layout components are imported from `Filament\Schemas\Components\*` (`Section`, `Grid`, `Fieldset`) and `Filament\Schemas\Components\Utilities\Get` in the guide's examples. The guide does not list the namespace moves exhaustively; it leaves them to the script.
- **Layout defaults**: `Grid`, `Section`, `Fieldset` no longer span all columns; `columnSpan()` targets `>= lg`.
- **Behaviour**: `unique()` ignores the current record by default; enum fields always return enum instances; table filters deferred by default; `all` pagination option off by default; default primary-key sort on tables; `Radio::inline()` changed.
- **Storage**: `default_filesystem_disk` reads `FILESYSTEM_DISK` (not `FILAMENT_FILESYSTEM_DISK`); file visibility `private` by default on non-local disks.
- **URLs**: resource page query parameters renamed (`activeRelationManager` → `relation`, `tableFilters` → `filters`, `tableSearch` → `search`, etc.).
- **Tenancy**: automatic global scoping and association of tenant-aware models.
- **Extension points**: `Field::make()`, `Builder\Block::make()`, `Entry::make()`, `Column::make()`, `ExportColumn/ImportColumn::make()` signatures changed (new `getDefaultName()`); the guide recommends overriding `setUp()` instead. Undocumented `can*()` overrides on resources and relation managers "aren't always called in v4"; use `get*AuthorizationResponse()` or policies.
- **RichEditor**: underlying editor moved from Trix to TipTap; `disableGrammarly()` removed.
- **Directory structure**: new default layout for resources/clusters (`filament:upgrade-directory-structure-to-v4`); `file_generation.flags` can keep v3-style generation.
- **Ecosystem**: the official Spatie Translatable plugin "will not receive v4 support" and is deprecated in favour of Lara Zeus's fork.
- **Translations**: `pt_PT`→`pt`, `np`→`ne`, `no`→`nb`, `kh`→`km`.
- Import/export jobs: 3 retries with 60 s backoff instead of 24 h of retries; the `Login` event is no longer fired inside the jobs.

### v4 → v5 (Livewire 4)

- The 5.x guide lists new requirements (PHP 8.2+, Laravel 11.28+, **Livewire v4.0+**, Tailwind CSS v4.0+), the automated script (`filament/upgrade:^5.0`, `vendor/bin/filament-v5`), and points to the Livewire 4 upgrade guide. It lists **no Filament-specific breaking changes**. https://filamentphp.com/docs/5.x/upgrade-guide
- The v5.0.0 release notes list async requests, AI docs/Blueprint, Livewire component registration refactor and Livewire 4 beta fixes. https://github.com/filamentphp/filament/releases/tag/v5.0.0
- The 5.x plugin docs still say "To update a plugin from Filament 3 to 4, follow the upgrade guide"; the 5.x `plugins/getting-started` page is byte-identical to the 4.x one. A v4→v5 plugin upgrade section is **not documented**. https://filamentphp.com/docs/5.x/plugins/getting-started#upgrading-existing-plugins
- The 5.x "Build a panel plugin" tutorial states "The examples below use Filament 4 APIs". https://filamentphp.com/docs/5.x/plugins/building-a-panel-plugin

## 7. RichEditor extension APIs (Filament 5)

Source: https://filamentphp.com/docs/5.x/forms/rich-editor unless noted.

- Editor: TipTap. Content stored as HTML by default; `->json()` stores TipTap JSON (needs an `array` cast). § Introduction, § Storing content as JSON.
- `filament/forms` v5.10.3 requires `ueberdosis/tiptap-php ^2.0` (server-side TipTap). https://repo.packagist.org/p2/filament/forms.json
- Livewire nesting: deep content may hit `MaxNestingDepthExceededException`; raise `payload.max_nesting_depth` in `config/livewire.php` (default 10 levels). § Configuring Livewire's maximum nesting depth.
- **Custom blocks** (§ Using custom blocks):
  - `RichEditor::make('content')->customBlocks([HeroBlock::class, ...])`; scaffold with `php artisan make:filament-rich-content-custom-block HeroBlock`.
  - Class extends `Filament\Forms\Components\RichEditor\RichContentCustomBlock`; static methods: `getId()` (abstract), `getLabel()`, `getIcon()`, `configureEditorAction(Action $action)` (modal `schema()` whose data becomes the block's `$config`), `toPreviewHtml(array $config)`, `getPreviewLabel(array $config)`, `shouldApplyProseStylingToPreview()`, `toHtml(array $config, array $data)`. **(source)** https://github.com/filamentphp/filament/blob/5.x/packages/forms/src/Components/RichEditor/RichContentCustomBlock.php
  - Stored as `<div data-type="customBlock" data-config="{json}" data-id="{blockId}">`. **(source)** https://github.com/filamentphp/filament/blob/5.x/packages/forms/src/Components/RichEditor/TipTapExtensions/CustomBlockExtension.php
  - Editor options: icons, grid display, groups, search, open panel by default, `minimalCustomBlockControls()`. §§ Adding icons … Opening the custom blocks panel by default.
- **Rendering** (§ Rendering rich content): `RichContentRenderer::make($content)->customBlocks([HeroBlock::class => ['categoryUrl' => ...]])->toHtml()`; also `mergeTags()`, `textColors()`, `fileAttachmentsDisk()`, `fileAttachmentsVisibility()`, `plugins()`. The renderer lives in `filament/forms`, not in the panel package.
- **Security** (§ Security, § Rendering content with custom blocks):
  - Raw HTML from the editor can be forged by the client; output must be sanitized (`str($html)->sanitizeHtml()`).
  - The renderer sanitizes surrounding content but treats HTML returned by `toHtml()` / `toPreviewHtml()` as **trusted**; `$config` must be treated as untrusted and escaped.
  - The built-in sanitizer allows inline `style` attributes (so `background: url(...)`, `position: fixed` survive).
- **Model-level registration** (§ Registering rich content attributes): `HasRichContent` + `InteractsWithRichContent`, `setUpRichContent()` with `registerRichContent('content')->customBlocks(...)->mergeTags(...)->mentions(...)->plugins(...)`; then `$record->renderRichContent('content')`. One configuration shared by editor and renderer.
- **RichEditor plugins** (§ Extending the rich editor): class implementing `Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin` with `getTipTapPhpExtensions()`, `getTipTapJsExtensions()` (URLs, async-loaded), `getEditorTools()` (`RichEditorTool` with `jsHandler()` or `action()`), `getEditorActions()` (`EditorCommand`, `runCommands()`); optional `HasToolbarButtons`. Registered with `->plugins([...])` on both editor and renderer.
- JS extensions are built with esbuild and registered via `FilamentAsset`; Filament exposes its bundled TipTap/ProseMirror on `window.FilamentRichEditor.tiptap` (`core`, `pmState`, `pmView`, `pmModel`) to avoid duplicate ProseMirror instances. § Setting up a TipTap JavaScript extension, § Sharing the bundled TipTap/ProseMirror instance.
- Other built-ins: merge tags, mentions (`MentionProvider`), text colours, `RichContentFaker`. §§ Using merge tags, Using mentions, Generating fake rich content.

## 8. Builder field extension APIs (Filament 5)

Source: https://filamentphp.com/docs/5.x/forms/builder unless noted.

- `Builder::make('content')->blocks([Block::make('heading')->schema([...]), ...])` stores a JSON array; docs recommend a JSON column with an `array` cast. § Introduction.
- Item shape in state: keyed by UUID, each `['type' => '<block name>', 'data' => [...]]`. § Adding extra item actions to a builder.
- Blocks: `label()` (can depend on item state), `icon()`, `preview('view.name')` with `blockPreviews(areInteractive:)`, per-block usage limit with `Block::maxItems()`, `$get()` to parent fields; item numbering on by default (`blockNumbers(false)` disables it). §§ Setting a block's label … Using `$get()`.
- Picker: block groups, columns, width, search. § Customizing the block picker.
- Performance: `maxItemsForProcessing()` (HTTP 413 above the limit, off by default, also blocks editing existing records over the limit); `Schema::make()->deferLoading()` per block, loaded when expanded and in viewport. §§ Limiting item processing, Deferring the loading of block schemas.
- Actions: customizable item actions, confirmation modals, `extraItemActions()` with `getItemState()` (validated) / `getRawItemState()`. §§ Customizing the builder item actions, Adding extra item actions.
- Subclassing `Builder\Block`: since v4 `make()` has a new signature; override `getDefaultName()` / `setUp()` instead of `make()`. https://filamentphp.com/docs/4.x/upgrade-guide (Low-impact changes)
- The docs describe no front-end renderer for Builder data: "On the frontend of your website, you could loop through each block in the JSON and format it how you wish." § Introduction.

## 9. Not documented

- A v4→v5 upgrade path specific to plugins.
- How two plugins resolve conflicting `register()` calls on the same panel (e.g. two `viteTheme()` calls); the source shows `register()` runs in the order `plugin()` is called. **(source)** HasPlugins.php.
- Any guarantee of API stability for plugin-facing classes between minors.
