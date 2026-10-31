# Content types

What a site holds is declared in code: each kind of content is a Content Type, a PHP class listed in `config/kami.php`. Its Entries are written through `SaveEntry` and read through the `Entry` model. Showing them at their address is in [Routing and rendering](routing-and-rendering.md).

## Declaring a type

A Content Type is a `final` class extending `Psyao\Kami\Content\ContentType`. Its `fields()` returns the Fields it stores:

```php
namespace App\Content;

use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Fields\Text;

final class Article extends ContentType
{
    protected ?string $prefix = 'articles';

    public function fields(): array
    {
        return [
            Text::make('summary'),
        ];
    }
}
```

List it in `config/kami.php`:

```php
'types' => [
    App\Content\Article::class,
],
```

Every Entry has a title, a slug, a status and a publication date of its own: they are not Fields. The type adds the rest.

Removing a type from the list deletes nothing: its Entries are kept and answer 404 until it is listed again. `kami:doctor` warns about them.

### Handle

A type's handle is the snake_case of its class name: `Article` is `article`, `NewsItem` is `news_item`. Kami stores it with every Entry and names the type's table and view after it. To rename the class without changing the handle, override `handle()`:

```php
public function handle(): string
{
    return 'article';
}
```

A handle is snake_case (lowercase letters and digits, words joined by single underscores) and 40 characters at most, so the table `kami_type_<handle>` fits every engine. Two types may not share a handle. Kami checks this the first time something reads the types: a mistake fails every page, admin screen and `kami:` command, with a message naming the class and the handle. Composer's `package:discover` still runs, so the fix can be deployed.

### Prefix

`$prefix` is the fixed start of the type's Paths. It defaults to the handle; `''` puts the type's Entries at the root of the site:

| `$prefix` | Entry with slug `hello` |
| --- | --- |
| not set (handle `article`) | `/article/hello` |
| `'articles'` | `/articles/hello` |
| `''` | `/hello` |

Changing a Prefix moves the type's Entries on the next `kami:install` ([Routing and rendering](routing-and-rendering.md#after-a-deploy)).

### Types with no URL

A collection listed on other pages, with no page of its own, declares `$routable = false`:

```php
final class Project extends ContentType
{
    protected bool $routable = false;
}
```

Its Entries have no Path and no slug, `$entry->url()` is `null`, its form has no slug field, `SaveEntry` refuses a `slug`, and `kami:doctor` asks for no view. A type that had URLs answers 404 from the deploy on, and the next `kami:install` removes its Paths; its slugs stay stored, so setting it back to `true` restores those addresses. An Entry saved with no slug meanwhile has no address until its next save.

### Singletons

A type with exactly one Entry per Site, such as a home page, extends `Singleton`:

```php
use Psyao\Kami\Content\Singleton;

final class Home extends Singleton
{
    protected ?string $prefix = '';
}
```

- **Its Path is its Prefix.** `Home` answers `/` and, published in `en`, `/en`. A Singleton with no `$prefix` answers at its handle. It has no slug: `SaveEntry` refuses one, and publishing needs none.
- **One Entry.** `SaveEntry` refuses a second Entry of the type with a validation error on `type`. `kami:install` creates the first as a Draft titled with the type's label; to update it, pass it as usual.
- **Reading it.** `Home::entry()` returns the Entry when visible in the current Locale, `null` otherwise, from any view.
- **In the admin.** Its navigation item, in the singular, opens its form directly, at `/admin/home`, with no list, create or delete. Where `kami:install` has not run since the type was declared, the page answers 404. The page is an edit form, so it takes the type's `update` Permission, and `publish` once the Entry is published: a User without them gets a 403 from the navigation item.

A route of your own at `/` wins over Kami's: remove Laravel's default `welcome` route for `Home` to answer.

### Global Sets

Values shown across the Site, such as footer details, go in a Singleton with no URL, which extends `GlobalSet`:

```php
use Psyao\Kami\Content\Fields\Email;
use Psyao\Kami\Content\Fields\Text;
use Psyao\Kami\Content\GlobalSet;

final class Footer extends GlobalSet
{
    public function fields(): array
    {
        return [
            Text::make('tagline'),
            Email::make('contact'),
        ];
    }
}
```

- **Reading it.** `Footer::entry()` works in any view and ignores visibility; a blank Translatable value falls back to the default Locale as usual. Each call costs three queries: in a view read several times, keep it in a variable.
- **Always published.** `kami:install` creates it in the default Locale, and every save publishes the Locale it writes. `SaveEntry` refuses `status` and `published_at`, and fills a blank `title` with the type's label.
- **No URL.** A `$prefix` other than its handle is an error, and `$routable` is ignored.
- **A required Field needs a default.** `kami:install` publishes the Entry before anyone can fill it, so `->required()` with no `->default()`, on a Field or a `Group`'s sub-Field, is an error when the type is first read.
- **In the admin.** Its form shows its Fields only. Saving changes what visitors see, so its page and navigation item take `publish` as well as `update`.

### Site settings

The package declares one Global Set itself, always registered: `SiteSettings`, with its table and labels. There is no second settings API.

```php
use Psyao\Kami\Content\SiteSettings;

SiteSettings::entry()?->site_name;

resolve(SaveEntry::class)->handle(SiteSettings::class, ['site_name' => 'Acme'], SiteSettings::entry());
```

`site_name` is Translatable and blank until written. To change its labels, set `site_settings` in your `kami-types.php`.

## Fields

A Field is built with `::make()` and its handle, which names its column:

| Field | Holds | Column |
| --- | --- | --- |
| `Text` | a single line of plain text | `string` |
| `Textarea` | several lines of plain text | `text` |
| `Boolean` | yes or no, read as a `bool`, `null` until given | `boolean` |
| `Url` | an `http` or `https` address, 255 characters at most | `string` |
| `Email` | an email address, 255 characters at most | `string` |
| `Number` | a whole number, read as an `int` | `integer` |
| `Number` with `->decimal()` | a number with up to two decimals, up to 999999.99, read as a `string` such as `'12.50'`, which stays exact | `decimal` |
| `Date` | a calendar day, read as a `CarbonImmutable` at midnight UTC | `date` |
| `DateTime` | a moment, read as a `CarbonImmutable` in UTC | `dateTime` |
| `Select` | one key out of `->options(['news', 'review'])` | `string` |
| `Repeater` | a list of rows of sub-Fields, read as a list of arrays, `null` with no row | `json` |
| `Group` | one set of named sub-Fields, read as an array, `null` when all are blank or off | `json` |
| `Entries` | links to other Entries, read as a Collection in pick order | none: [`kami_references`](#relations) |

`Text` and `Textarea` are Translatable: one value per Locale. Every other type is Shared: one value for the whole Entry. `->shared()` and `->translatable()` override that; see [Multilingual](multilingual.md).

`->required()` makes a Field required to publish, while a Draft may leave it blank; `->rules([...])` takes any Laravel validation rule, checked on every save. See [Publishing](publishing.md#validation).

Each type refuses what it cannot hold, with no rule of yours: `Url::make('website')` refuses a non-URL on every save.

A `DateTime` is stored in UTC, whatever zone it is given in, and shown in the Site's time zone in the admin, as `published_at` is. A `Date` has no time, so no zone converts it: the day given is the day stored, in the admin and in code.

`->filterable()` and `->sortable()` ask `kami:make:migration` for an index on the Field's column, for a listing that filters or sorts on it. A column has no index otherwise. On a `text` or `json` column, which no engine indexes whole, either call is an error at the first read of the declarations.

`->default($value)` is the value a new Translation stores when the save gives none, and the admin's create form shows it pre-filled. A Shared Field takes it only while the Entry has no Shared value yet, so adding a Locale never overwrites one. An update never applies a default, and a value given as `null` stays `null`:

```php
Text::make('author')->shared()->default('The newsroom'),
```

### Repeaters and groups

A `Repeater` holds a variable list, and a `Group` one set of values that belong together. Each takes its sub-Fields as the second argument of `make()`:

```php
Repeater::make('links', [
    Text::make('label'),
    Url::make('url')->required(),
]),
Group::make('address', [
    Text::make('street'),
    Text::make('city'),
]),
```

```php
$entry->links;   // [['label' => 'Site', 'url' => 'https://example.test'], ...]
$entry->address; // ['street' => 'Rue du Lac 1', 'city' => 'Vevey']
```

Each sub-Field reads through its own type, so a `Date` in a row is a `CarbonImmutable`, and every declared one is present, `null` when blank. Keys that no sub-Field declares are not stored. Editors add, remove and reorder a Repeater's rows. A row added and left blank stays until an editor removes it, so on a translatable Repeater it holds that Locale's value and stops Fallback. A `Group` whose values are all blank or `false` stores `null`, as a toggle left untouched says nothing.

A sub-Field's rules and `->required()` apply to each row, as at the top level: a Draft may leave a required one blank. An error names its row (`links.1.url`), and in the admin it shows on that row's input. A `->required()` inside a `Group` holds to publish even when the Group itself is not required.

The Field's scope covers the whole value: both are Shared unless you call `->translatable()` on them, and a sub-Field's own scope is ignored. Repeaters and groups may nest. Their sub-Field labels live under the parent's key; see [Labels](#labels).

The value is one document, which SQL never looks into: to filter on a value, make it a top-level Field. So the first read of the declarations refuses, inside either one, a layout object, a sub-Field with `->visibleWhen()`, `->filterable()`, `->sortable()` or `->default()`, and a Repeater or Group with no sub-Field. Neither can be the target of a condition. A relation cannot sit in one either: its rows live in `kami_references`, which cannot see inside a document. A custom Field type inside one needs its component, as anywhere.

### Relations

`Entries` links an Entry to Entries of the types you name, each listed in `types`:

```php
Entries::make('related')->of(Article::class, Page::class),
```

`SaveEntry` takes a list of Entry ids, in the order to keep. Giving the Field replaces its links, a blank list removes them, and leaving it out keeps them. An unknown id, an Entry of another type or another Site, or the same id twice is a validation error on the Field. A Draft may be linked: reads leave it out until it is visible.

```php
app(SaveEntry::class)->handle(Project::class, ['related' => [$second->id, $first->id]], $project);
```

```php
$projects = Entry::query()->ofType(Project::class)->with('related')->get();

foreach ($projects as $project) {
    $project->related; // Collection<int, Entry>, in pick order
}
```

`with()` names the Field and loads every listed Entry's links with one query for the rows, then the targets' usual queries: the Entries, their Translations and one per target type. Several relation Fields share those queries, and `with('related.related')` follows a link further. Unlike an Eloquent relation, it takes no constraint: filter the Collection, or query the type. Read without `with()`, among several Entries, it throws under Laravel's strict mode, as a lazy relation does; a single Entry, as a type's view has, loads it on first read.

Reads leave out a target not visible in the Locale read, and `withUnpublished()` on the query brings them back. Hard-deleting a target removes its links, and hard-deleting an Entry removes the links it holds.

A relation is Shared by default. With `->translatable()`, each Locale has its own list, and a blank one falls back to the default Locale's, while that Translation is visible, as for any Field ([Multilingual](multilingual.md)). A Locale with links whose targets are all hidden is not blank: it reads empty. Fallen-back targets are checked in the Locale read too.

A relation can be conditional, and hidden it loses its links in every Locale. The first read of the declarations refuses one with no `->of()` or a type not in `types`, with `->filterable()`, `->sortable()` or `->default()`, as the target of a condition, `->required()` on a Global Set, and inside a Repeater or Group.

Editors search targets by title and see them in pick order, Drafts included. They reorder by dragging; on a keyboard, removing a target and picking it again puts it last.

With `->creatable()`, editors holding the target type's `create` Permission also create a target from the picker, with a title alone, in the Locale they edit. It goes through `SaveEntry`, as a Draft. The first read of the declarations refuses it when the picker could not do so: several target types, a Singleton, or a target with a `->required()` Field its default does not fill.

### Taxonomies

A Taxonomy classifies other Entries. Its Entries are Terms, and everything an Entry has, a Term has: Fields, Translations, a Path.

```php
final class Tag extends Taxonomy
{
    protected bool $translatable = false; // one name for every Locale
    protected bool $routable = false;     // no address of its own
}
```

`Terms` links them, like `Entries`, and refuses a target that is no Taxonomy:

```php
Terms::make('tags')->of(Tag::class),
```

```php
$project->tags;       // Collection<int, Term>, in pick order
$term->entries;       // Collection<int, Entry>, the visible Entries linking it, once each
$term->entries()->withUnpublished()->count();

Term::query()->ofType(Tag::class)->with('entries')->get(); // no N+1
```

`entries()` is an Eloquent relation, across every relation Field naming the Term, reading visible Entries in the current Locale, whatever the Term's own query says: ask for more on the relation, with `$term->entries()->inLocale('en')` or `with(['entries' => fn ($query) => $query->withUnpublished()])`.

`SaveEntry` publishes a new Term when `status` is left out; a Draft is asked for by name. The picker's creation does the same, so a Tag needs no second step, for an editor holding the Taxonomy's `publish`; without it, the Term is a Draft, left out of reads until someone publishes it. A Taxonomy's own form keeps its two buttons, Save draft and Publish.

`Terms` is creatable by default. Name several Taxonomies or a Taxonomy with a required Field, and add `->creatable(false)`, as the first read of the declarations will ask.

### Your own model

`$model` names the `Entry` subclass a type's Entries hydrate to, so domain methods sit on the model, in any listing:

```php
final class Project extends ContentType
{
    protected ?string $model = \App\Models\Project::class;
}

class Project extends Entry // App\Models
{
    public function host(): string
    {
        return parse_url($this->site_url, PHP_URL_HOST);
    }
}
```

Each row hydrates to its own type's model, whichever model queried it: `Entry::query()->get()` may mix them, and `Project::query()` is no filter, so keep `->ofType()`. A Taxonomy's model extends `Term`, any other's `Entry`; the first read of the declarations refuses anything else, and a Field handle naming one of the model's methods.

### Tabs and sections

`fields()` may arrange its Fields in a `Tab` or a `Section`, from `Psyao\Kami\Content`. They shape the form and nothing else: columns, validation and reads see the same flat list of Fields, and moving a Field between them needs no migration.

```php
use Psyao\Kami\Content\Section;
use Psyao\Kami\Content\Tab;

public function fields(): array
{
    return [
        Tab::make('details', [
            Text::make('client'),
            Section::make('highlight', [
                Boolean::make('featured'),
            ]),
        ]),
        Tab::make('story', [
            Textarea::make('story'),
        ]),
        Date::make('launched_on'),
    ];
}
```

Adjacent Tabs form one set of tabs. A `Section` sits at the top level or in a `Tab`; a `Tab` sits at the top level only. A layout handle follows the rules of a Field handle and appears once in its type; it may equal a Field's, as `story` does here. In the form, the title and the slug stay above the declared Fields, and the publication date below. Their titles are in [Labels](#labels).

### Conditional Fields

A Field can show only when it applies, according to another Field of the same type, wherever each is arranged:

```php
Boolean::make('featured'),
Text::make('featured_note')->visibleWhen('featured', true),
Select::make('kind')->options(['news', 'review', 'essay']),
Text::make('source')->shared()->visibleWhen('kind', ['review', 'essay']), // one of these
Text::make('client')->shared(),
Textarea::make('story')->visibleWhenFilled('client'),                     // any value
```

A hidden Field is neither validated nor stored, not even required to publish, and takes no `default()`. `SaveEntry` applies this whoever calls it, so an import and the form give the same result:

- A value given for a hidden Field is ignored, without an error.
- A stored value is cleared as soon as its Field becomes hidden, and does not come back when the Field shows again.
- A Translatable Field is cleared in every Locale, since the target holds for all of them.

A Boolean with no value reads as `false`, as an untouched toggle does.

At the first read of the declarations, it is an error for a condition to name a Field the type does not declare, one that is itself conditional, or a Translatable one: a target holds for every Locale, so it must be Shared, the default of every type but `Text` and `Textarea`. So is `visibleWhenFilled()` on a Boolean, which always holds a value, and a `visibleWhen()` value that is not an option of its `Select`. Anything richer belongs in code of your own, in front of `SaveEntry`.

### Your own Field type

A type Kami lacks is a class extending `Field`. Every hook is optional: the default is a Translatable `string` column, with no rule of its own, read as stored.

```php
use Psyao\Kami\Content\Fields\Field;

final class Colour extends Field
{
    // Shared by default; `->translatable()` still overrides it.
    protected bool $translatable = false;

    // `string`, `text`, `boolean`, `integer`, `decimal`, `date` or `dateTime`, the column types kami:make:migration can compare.
    public function column(): string
    {
        return 'string';
    }

    // Checked on every save, before those of `->rules()`.
    protected function baseRules(): array
    {
        return ['hex_color'];
    }

    // What the column stores for a valid value given to SaveEntry; never called with `null`.
    protected function store(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }

    // What `$entry->accent` returns for the stored value; never called with `null`.
    protected function cast(mixed $value): mixed
    {
        return is_string($value) ? strtolower($value) : $value;
    }
}
```

Its admin component is registered on the plugin, by the Field type's exact class, as a closure given the Field and returning a Filament form field:

```php
->plugin(KamiPlugin::make()->fieldComponents([
    Colour::class => fn (Colour $field) => ColorPicker::make($field->handle),
]))
```

The same call replaces the component of one of Kami's own Field types. A declared Field type with no component stops the panel with an error naming it; Artisan commands keep working, so you can fix it.

### Field handles

A Field handle follows the type handle's rules (snake_case, 40 characters at most) and appears once in its type. It may not take a name Kami keeps for its own attributes, listed in `Field::RESERVED_HANDLES`:

`id`, `site`, `type`, `created_by`, `updated_by`, `created_at`, `updated_at`, `title`, `slug`, `status`, `published_at`, `meta_title`, `meta_description`, `noindex`, `entry_id`, `locale`, `parent`, `parent_id`, `position`, `deleted_at`.

Nor may it name a method of `Entry`, Eloquent's included, such as `url`, `translations`, `save` or `touch`: the method would hide the value. The list only grows in a major version. Each rule is checked with the type's handle.

## The type's table

A type's Field values live in its own table, `kami_type_<handle>`, one row per Locale and one `'*'` row for the Shared Fields (ADR 0006). Kami writes its migration for you:

```bash
php artisan kami:make:migration
```

The command compares every declared type with the database and writes one ordinary migration into `database/migrations`, then prints its path. It never runs it: read it, commit it, and `kami:install` (or `php artisan migrate`) runs it with Kami's own. Pass a handle or a class (`kami:make:migration article`) to look at one type only. When nothing differs, it says "Nothing to do" and writes no file.

What it writes:

- **A new type**: its table, with `entry_id` (deleted with the Entry), `locale`, one unique row per Entry and Locale, and one column per Field.
- **An added Field**: a new column. **A removed Field**: a dropped column, with its values. **A Field going between a `string` and a `text` column** (`Text` to `Textarea`): the column's new type.
- **A Field declared `->filterable()` or `->sortable()`**: an index on its column. **A Field no longer declared so**: that index dropped. An index you wrote by hand on the column counts as its index, and is dropped only with its column.
- **A down() method** that undoes all of it.

Every Field column is nullable, with no database default: a Draft may be incomplete, and a Field's `default()` lives in the code. Each Field type's column is listed under [Fields](#fields). A [relation](#relations) has no column, so the command ignores it. A type with no column gets no table, and a type you remove from `types` keeps its table: the command never drops one.

Renaming a Field looks like removing one and adding another, and the drop would lose its values. So when a type both loses and gains columns, the command asks in a terminal, for each lost column, whether it is renamed to one of the new ones (`renameColumn`, values kept) or dropped. Without a terminal (a deploy script, `--no-interaction`), it refuses and prints both options rather than guess.

Any other change of column type, such as `Text` to `Boolean`, `Number` to `Date` or `Textarea` to `Repeater`, is refused, on every engine: a value that does not convert has no answer that holds on all of them, so the conversion is yours to write. The command writes no file and prints the migration to start from. It adds a column of the new type, copies each row's value to it, drops the old column and renames the new one, keeping any index the new type can hold. Convert the value where the comment says. It holds only these changes and the renames they rely on, so run the command again once it has run, for the type's other changes and for other types.

That migration runs from the command line, like any other (`kami:install`), so a web request's time limit does not apply; it updates one row at a time, which takes longer the more Entries the type has. On MySQL and MariaDB, a value that fails partway leaves the new column behind, since their schema changes do not roll back: drop it before running the migration again.

SQLite stores a `json` column as `text`, so it cannot tell them apart by type: a `text` column declared `json` passes there while every value in it decodes to an array or an object, and is refused otherwise. MariaDB stores it as `longtext`, under the same check. The other way, `Repeater` to `Textarea` writes nothing on SQLite, and the stored JSON reads as text.

Going from `Textarea` to `Text`, a value over 255 characters stops the migration with an error and loses nothing on PostgreSQL, MySQL and MariaDB, while SQLite keeps it whole. MySQL and MariaDB truncate it without the connection's `strict` mode, which Laravel turns on by default.

Run `php artisan migrate` between two runs: while a migration that touches a `kami_type_*` table is pending, the command refuses, since it would write the same change twice.

## In the admin

Each declared type gets its own item in the panel's navigation, at the plural of its handle: `article` at `/admin/articles`, `news_item` at `/admin/news-items`; a [Singleton](#singletons) is at its handle. Its form is generated from the declaration: title, slug unless the type has none, then each Field in its [tab or section](#tabs-and-sections), appearing and disappearing as its [condition](#conditional-fields) changes, `Text` as a single-line input, `Textarea` as a multi-line one, `Url`, `Email` and `Number` as inputs of their kind, `Date` and `DateTime` as pickers, the latter showing the Site's time zone, `Select` as a dropdown, and `Boolean` as a toggle, which saves `false` when left off, so a `->translatable()` Boolean saved from the form never falls back. Leaving the title fills the slug, as [Publishing](publishing.md#slugs-and-paths) describes. Publishing from the form is in [Publishing](publishing.md#in-the-admin).

A User sees a type only with its `view` Permission ([Users and roles](users-and-roles.md#permissions)).

### Labels

Labels come from the site's `lang/<locale>/kami-types.php`, by handle, never from the declaration; the package ships those of [`SiteSettings`](#site-settings), which a site's file overrides. A missing key falls back to the humanised handle: `news_item` reads `News Item`, its plural `News Items`, and a Field with no `help` has no help text:

```php
return [
    'article' => [
        'label' => 'Article',
        'plural' => 'Blog posts',
        'tabs' => ['details' => ['label' => 'Details']],
        'sections' => ['highlight' => ['label' => 'Highlight']],
        'fields' => [
            'summary' => ['label' => 'Summary', 'help' => 'Two sentences at most.'],
            'kind' => ['label' => 'Kind', 'options' => ['news' => 'News', 'review' => 'Review']],
            'links' => ['label' => 'Links', 'fields' => ['url' => ['label' => 'Address']]],
        ],
    ],
];
```

The same strings are read in code through the type: `label()`, `pluralLabel()`, `fieldLabel('summary')`, `fieldHelp('summary')`, `optionLabel('kind', 'news')`, with `links.fields.url` for a sub-Field, `tabLabel('details')` and `sectionLabel('highlight')`. `kami:doctor` warns about each missing `label`, `plural`, Field or sub-Field `label`, `Select` option key and tab or section `label`, in the application's language and each of the Site's Locales; `help` stays optional. It also warns about a type with no `kami.<handle>` view that keeps the default `render()` ([Routing and rendering](routing-and-rendering.md#rendering)).

## Writing Entries

Every write goes through `Psyao\Kami\Content\Actions\SaveEntry`, from seeders, commands and imports alike. It takes the type, by class or handle, and the values:

```php
use App\Content\Article;
use Psyao\Kami\Content\Actions\SaveEntry;

$entry = app(SaveEntry::class)->handle(Article::class, [
    'title' => 'Hello world',
    'status' => 'published',
    'summary' => 'A first article.',
]);
```

- `slug` defaults to the slug of the title: `hello-world`. A [Singleton](#singletons) or a [type with no URL](#types-with-no-url) takes none.
- `status` is `draft` (the default) or `published`.
- `published_at` defaults to now when publishing. A date to come makes the Entry Scheduled: it stays hidden until then, with no task to run.

Invalid values throw Laravel's `ValidationException`; states, validation and slugs are in [Publishing](publishing.md).

To update an Entry, pass it as the third argument; values left out are kept:

```php
app(SaveEntry::class)->handle(Article::class, ['title' => 'Hello again'], $entry);
```

The write is saved in the site's default Locale; other Locales are in [Multilingual](multilingual.md). `SaveEntry` does not check who is calling: authorise before calling it from a request ([Publishing](publishing.md#saveentry-does-not-authorise)).

## Reading Entries

`Psyao\Kami\Content\Entry` is an Eloquent model. Its title, slug, status, publication date and Field values read as attributes:

```php
$entry->title;        // 'Hello world'
$entry->summary;      // 'A first article.'
$entry->published_at; // CarbonImmutable, in UTC
```

Its queries return only visible Entries: published, with a publication date that has come. Drafts and Scheduled Entries need `withUnpublished()`, so a forgotten scope never shows them:

```php
Entry::query()->ofType(Article::class)->get();                     // visible Articles
Entry::query()->ofType('article')->withUnpublished()->get();       // every Article
```

Field values load with the Entries, in one query per type, so a list costs the same few queries whatever its length. Lazy loading throws. For large sets use `lazy()` or `chunk()`: `cursor()` throws, as it would load no Field value.
