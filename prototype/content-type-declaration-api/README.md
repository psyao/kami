# PROTOTYPE: Content type declaration API (psyao/kami#11)

Throwaway. Never merged. Answers one question: how does a developer declare a Content Type,
its Fields, blocks, validation, layout, conditional Fields, reusable fieldsets and relations,
in a few readable lines, without tying the core to Filament, and with an escape hatch to full Filament?

```bash
php run.php                 # all three sites
php run.php aguet-dev       # one site
```

`run.php` prints what Kami would derive from the declarations: the `kami_type_<handle>`
table and its columns (ADR 0006), the Fields stored in `kami_references` (ADR 0005),
validation rules, conditions and the admin layout tree.

## Files

| File | What to react to |
|------|------------------|
| `sites/aguet-dev/types.php` | Home (Singleton, tabs, section, reusable fieldset, conditional Field), Project, SkillGroup, Tag |
| `sites/psyao-ch/types.php` | Post, Page, Category, Tag (Taxonomies, Blocks in rich text) |
| `sites/steve-et-aline-ch/types.php` | Gallery (Media Fields, hierarchy), Gallery Block |
| `admin/ProjectForm.php` | The escape hatch to full Filament (does not run) |
| `variants/B-attributes.php` | Rejected-by-default variant: typed properties + attributes |
| `variants/C-closures.php` | Rejected-by-default variant: Blueprint-style closures |
| `kami.php` | Fake core, only enough for `run.php`. Not a design. |

## Choices baked into variant A (the main one)

1. One `final` class per Content Type extending `ContentType`, `Singleton`, `GlobalSet` or `Taxonomy`; `fields(): array` of Field objects (Velox ADR 0003's shape, kept).
2. Handle = snake_case of the class name; override `handle()` to keep it after a rename.
3. Type behaviour as protected properties (`$prefix`, `$hierarchical`, `$orderable`, `$model`), not methods.
4. Registration: explicit list in `config/kami.php` (`'types' => [...]`), no directory scan.
5. Labels, tab titles and help texts come from `lang/<locale>/kami-types.php` by convention, never from the declaration.
6. Default scope by Field kind: text-like Fields Translatable, everything else (boolean, number, date, url, email, select, relations, Media) Shared; `->shared()` / `->translatable()` override.
7. `->required()` checked at publication only; `->rules()` takes any Laravel rule.
8. Layout (`Tab`, `Section`) lives in the core declaration as plain objects, never stored; handles unique across the whole type.
9. Conditions are declarative and limited: `->visibleWhen('field', value)` and `->visibleWhenFilled('field')`. A hidden Field is neither validated nor stored. Anything richer goes through the escape hatch.
10. Reusable fieldsets are plain PHP (a static method returning Fields, with a prefix). No Kami concept.
11. Relations: `Entries::make()->of(Type::class)` and `Media::make()`; Terms attach through an `Entries` Field to a Taxonomy, `->creatable()` to create a Term from the picker.
12. Blocks: classes with `handle()`, `fields()`, `view()`, offered with `RichText::make()->blocks([...])`. Storage belongs to "Editing: rich text format and blocks".
13. Read side: one `Entry` model, Field values as attributes (`$project->client`); an optional site-owned subclass via `$model` for methods; Larastan typing via generated PHPDoc stubs (tooling, not decided here).
14. Escape hatch: a site-owned `EntryForm` subclass in `app/Filament/`, registered on `KamiPlugin`, which chains on or rearranges the generated components but cannot add a component with no declared Field behind it.
