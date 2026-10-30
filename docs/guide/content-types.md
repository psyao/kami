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

`Text` and `Textarea` are Translatable: one value per Locale. Every other type is Shared: one value for the whole Entry. `->shared()` and `->translatable()` override that; see [Multilingual](multilingual.md).

`->required()` makes a Field required to publish, while a Draft may leave it blank; `->rules([...])` takes any Laravel validation rule, checked on every save. See [Publishing](publishing.md#validation).

Each type refuses what it cannot hold, with no rule of yours: `Url::make('website')` refuses a non-URL on every save.

A `DateTime` is stored in UTC, whatever zone it is given in, and shown in the Site's time zone in the admin, as `published_at` is. A `Date` has no time, so no zone converts it: the day given is the day stored, in the admin and in code.

`->filterable()` and `->sortable()` ask `kami:make:migration` for an index on the Field's column, for a listing that filters or sorts on it. A column has no index otherwise. On a `text` or `json` column, which no engine indexes whole, either call is an error at the first read of the declarations.

`->default($value)` is the value a new Translation stores when the save gives none, and the admin's create form shows it pre-filled. A Shared Field takes it only while the Entry has no Shared value yet, so adding a Locale never overwrites one. An update never applies a default, and a value given as `null` stays `null`:

```php
Text::make('author')->shared()->default('The newsroom'),
```

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
- **An added Field**: a new column. **A removed Field**: a dropped column, with its values. **A Field whose type changed** (`Text` to `Textarea`): the column's new type.
- **A Field declared `->filterable()` or `->sortable()`**: an index on its column. **A Field no longer declared so**: that index dropped. An index you wrote by hand on the column counts as its index, and is dropped only with its column.
- **A down() method** that undoes all of it.

Every Field column is nullable, with no database default: a Draft may be incomplete, and a Field's `default()` lives in the code. Each Field type's column is listed under [Fields](#fields). A type with no Field gets no table, and a type you remove from `types` keeps its table: the command never drops one.

Renaming a Field looks like removing one and adding another, and the drop would lose its values. So when a type both loses and gains columns, the command asks in a terminal, for each lost column, whether it is renamed to one of the new ones (`renameColumn`, values kept) or dropped. Without a terminal (a deploy script, `--no-interaction`), it refuses and prints both options rather than guess.

Run `php artisan migrate` between two runs: while a migration that touches a `kami_type_*` table is pending, the command refuses, since it would write the same change twice.

## In the admin

Each declared type gets its own item in the panel's navigation, at the plural of its handle: `article` at `/admin/articles`, `news_item` at `/admin/news-items`. Its form is generated from the declaration: title, slug, then each Field, `Text` as a single-line input, `Textarea` as a multi-line one, `Url`, `Email` and `Number` as inputs of their kind, `Date` and `DateTime` as pickers, the latter showing the Site's time zone, `Select` as a dropdown, and `Boolean` as a toggle, which saves `false` when left off, so a `->translatable()` Boolean saved from the form never falls back. Leaving the title fills the slug, as [Publishing](publishing.md#slugs-and-paths) describes. Publishing from the form is in [Publishing](publishing.md#in-the-admin).

A User sees a type only with its `view` Permission ([Users and roles](users-and-roles.md#permissions)).

### Labels

Labels come from the site's `lang/<locale>/kami-types.php`, by handle, never from the declaration. A missing key falls back to the humanised handle: `news_item` reads `News Item`, its plural `News Items`, and a Field with no `help` has no help text:

```php
return [
    'article' => [
        'label' => 'Article',
        'plural' => 'Blog posts',
        'fields' => [
            'summary' => ['label' => 'Summary', 'help' => 'Two sentences at most.'],
            'kind' => ['label' => 'Kind', 'options' => ['news' => 'News', 'review' => 'Review']],
        ],
    ],
];
```

The same strings are read in code through the type: `label()`, `pluralLabel()`, `fieldLabel('summary')`, `fieldHelp('summary')` and `optionLabel('kind', 'news')`. `kami:doctor` warns about each missing `label`, `plural`, Field `label` and `Select` option key, in the application's language and each of the Site's Locales; `help` stays optional. It also warns about a type with no `kami.<handle>` view that keeps the default `render()` ([Routing and rendering](routing-and-rendering.md#rendering)).

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

- `slug` defaults to the slug of the title: `hello-world`.
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

Field values load with the Entries, in one query per type, so a list costs the same few queries whatever its length. Lazy loading throws.
