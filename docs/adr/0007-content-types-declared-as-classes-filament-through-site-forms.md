# Content Types are classes declaring core Fields; Filament is reached through site-owned forms

A developer declares each Content Type as one `final` class extending `ContentType`, `Singleton`, `GlobalSet` or `Taxonomy`, listed in `config/kami.php` under `types`. Behaviour flags are protected properties (`$prefix`, `$routable`, `$hierarchical`, `$orderable`, `$translatable`, `$model`), and hooks with logic stay methods. `fields(): array` returns Field objects from `Psyao\Kami\Content\Fields`, optionally wrapped in `Tab` and `Section` layout objects:

```php
final class Project extends ContentType
{
    protected bool $orderable = true;
    protected ?string $model = \App\Models\Project::class;

    public function fields(): array
    {
        return [
            Text::make('client'),
            Textarea::make('summary')->inlineMarkdown(),
            Url::make('url'),
            Boolean::make('featured')->filterable(),
            Terms::make('tags')->of(Tag::class),
        ];
    }
}
```

The declaration, its layout and its conditions never reference Filament, so the front, the local API, imports and validation in `SaveEntry` all read the same declaration (ADR 0011). When the generated form is not enough, a site writes its own `EntryForm` or `EntryTable` subclass in `app/Filament/` and registers it with `KamiPlugin::make()->forms([...])` or `->tables([...])`. The subclass chains Filament calls onto the generated components, or rearranges them freely. It may not add a field component with no declared Field behind it, which would have no column (ADR 0006), and it may not leave out a declared Field: both are boot errors. Components that hold no value (layout, `Actions`, text) are allowed (ADR 0029). Storage therefore always comes from the declaration, and no stored value is ever hidden from editors.

This keeps Velox ADR 0003 (a Field abstraction of Kami's own, mapped to Filament) and Velox ADR 0009 (a site-owned subclass as the escape hatch, which unblocked adoption in I-100/104). It departs from Velox on layout and conditions: Velox could only express them by chaining Filament in a Resource subclass, so every type with tabs needed two files.

## Considered options

- **Typed properties with attributes** (`#[K\Text] public ?string $client`). The class would double as a typed read model, but tabs, sections and conditions have no natural home on properties. A property typed `string` lies when a Translatable field is read in another Locale. Declared properties also shadow Eloquent's `__get()`, which fights Laravel (principle 8).
- **Blueprint-style closures in a service provider** (`Kami::type('project', fn ($type) => ...)`). Closest to Laravel migrations, but types become strings: a typo is a boot error rather than an IDE or Larastan error, "find usages" finds nothing, and hooks need a second place to live.
- **Layout and conditions in `Admin` only.** The core would be purer, but every type with tabs would need an `EntryForm`, kept in step with the declaration by hand: Velox's rigidity again.
- **Closures for conditions or per-Field Filament closures** (`->visibleWhen(fn ($get) => ...)`, `->admin(fn ($c) => ...)`). A closure cannot be evaluated outside Filament, so server-side validation would diverge from the form, and Filament would leak into the core.

## Consequences

- **Handles.**
  - A type's handle is the snake_case of its class name; `handle()` is overridden to keep it after a class rename, otherwise the rename goes through `ContentMigrator::renameType` (ADR 0006).
  - A Field handle is snake_case, at most 40 characters and unique across the whole type, layout included. A sub-Field of a `Repeater` or `Group` is unique within its parent: it has no column, only a key in the parent's document.
  - A Field handle never matches a core attribute (`title`, `slug`, `status`, `published_at`, `parent`, `position`, `site`, `type`, `locale`…): a collision is a boot error. The reserved list is public under SemVer, and a new core attribute only arrives in a major version.
  - A Block's handle is explicit and frozen, since every stored node carries it.
- **URLs.** `protected bool $routable = true`; `false` gives a type no URL: no `kami_paths` row, no slug, `url()` null. Collections listed on another page, such as aguet.dev's Projects and Tags, need it. A `$prefix = null` cannot say it, as the prototype had it, since it has meant the handle since M2 (ADR 0008). A type flipped to `false` answers 404 at once and loses its 200 rows at the next `SyncPaths`, on purpose; its stored slugs stay, so flipping back restores those addresses; an Entry saved with no slug meanwhile has none until its next save. A `Singleton`'s Path is its Prefix: it takes no slug.
- **Global Sets.** A `GlobalSet` is never routable and takes no Prefix. A required Field with no default, a `Group`'s sub-Field included, is a boot error: `kami:install` publishes the Entry before anyone can fill it, and a deploy never fails on absent content.
- **Scope.**
  - A Field's default scope follows its type: Text, Textarea and RichText are Translatable; every other Field type is Shared. `->shared()` and `->translatable()` override it.
  - A type declared `$translatable = false` makes all its Fields Shared ("Multilingual content and fallback").
- **Validation.**
  - `->required()` is checked at publication, never on a draft (ADR 0006).
  - `->rules()` takes any Laravel rule.
- **Conditions** are declarative and limited to a Field of the same type: `->visibleWhen('field', value)` (equality or membership) and `->visibleWhenFilled('field')`. A hidden Field is neither validated nor stored. `SaveEntry` clears its value, so an import or the local API behaves like the form. Anything richer goes through the escape hatch.
  - A condition's target may not be conditional itself: the form keeps a hidden component's state, so a chain would show there what `SaveEntry` hides.
  - A target must be Shared: a Translatable one would answer differently per Locale and through Fallback, so a value given for one Locale could vanish silently while a reader sees the Field apply.
  - A hidden Field is cleared in every Locale, since its target holds for all of them. This weighs Stability (no value lost) against Simplicity (form, import and front agree). Stability's promise covers migrations, not an editor's own change, so it does not outrank here: a value kept would stay readable on the front while no editor can see or edit it, which this ADR refuses. The price is a translation's value, gone with no way back in Kami.
- **Labels.** Labels, tab titles and help texts never appear in a declaration.
  - They come from `lang/<locale>/kami-types.php`, by handle.
  - A missing key falls back to the humanised handle.
  - `kami:doctor` lists missing keys.
- **Reusable fieldsets** are plain PHP, with no Kami concept. A static method returns Fields under a prefix (`...SocialLink::fields('contact_linkedin')`), so columns stay flat, typed and unique.
- **Relations.**
  - `Entries::make()->of(Type::class, ...)` links Entries.
  - `Terms::make()->of(Taxonomy::class)` links Terms: multiple and creatable from the picker by default, and its target must be a `Taxonomy`.
  - `Media::make()` links Media.
  - `Entries` and `Terms` are both `final`, built on an internal base. All three store in `kami_references` (ADR 0005) and keep the order of selection.
- **Read side.**
  - One `Entry` model exposes Field values as cast attributes (`$project->client`).
  - A type may name its own subclass through `$model` for domain methods. A Taxonomy's default model is `Psyao\Kami\Content\Term extends Entry`, which adds `entries()`, `ancestors()` and its Archive URL.
  - Each relation returns its target type's model, so `$post->tags` is a `Collection<int, Term>`. `Entry` and `Term` are open classes.
  - Larastan typing comes from generated PHPDoc stubs (`kami:types`), not from the API.
- **Blocks.**
  - A Block is a `final` class extending `Block`, with `handle()`, `fields()` and `view()`, offered per Field with `RichText::make()->blocks([...])`. Kami's own Blocks are opt-in the same way.
  - Block Fields have no column. Their `Entries`, `Terms` and `Media` values are indexed into `kami_references` on save.
  - Inside a Block, conditions and validation are allowed; tabs, sections and nested Blocks are not.
  - Storage and rendering belong to "Editing: rich text format and blocks".
- **Field types.**
  - `Field` is a public, open abstract class. A site adds its own Field type by declaring its column, cast, rules and default scope, and how a value is prepared for its column (`store()`, which a `DateTime` uses to reach UTC).
  - Its admin component is registered with `KamiPlugin::make()->fieldComponents([...])`, the same mechanism Kami uses for its own Field types. A Field type with no registered component is an error when the panel boots, on its own requests: Artisan keeps working, so the declaration can be fixed.
  - `Repeater` and `Group` take no registered component: theirs need the parent's label keys and recurse through their sub-Fields, whose own components stay registered. No site has asked to replace them, and an `EntryForm` still can (simplicity, principle 6, against an extension point with no user).
  - `StructuredField`, their base, is public because a public class may not extend an internal one (ADR 0010). Its members are `@internal`, and a site does not extend it.
- **No infolist** in the MVP: an Entry is seen through its form or its front preview. `->infolists()` can be added later without breaking anything.
- **Open classes.** The extension points this adds (`Field`, `Entry`, `Term`, `EntryForm`, `EntryTable`, `fieldComponents`) join the closed list owned by "Extension points: events, registries, overrides".
