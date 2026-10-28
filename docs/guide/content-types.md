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

### Handle

A type's handle is the snake_case of its class name: `Article` is `article`, `NewsItem` is `news_item`. Kami stores it with every Entry and names the type's table and view after it. To rename the class without changing the handle, override `handle()`:

```php
public function handle(): string
{
    return 'article';
}
```

### Prefix

`$prefix` is the fixed start of the type's Paths. It defaults to the handle; `''` puts the type's Entries at the root of the site:

| `$prefix` | Entry with slug `hello` |
| --- | --- |
| not set (handle `article`) | `/article/hello` |
| `'articles'` | `/articles/hello` |
| `''` | `/hello` |

## Fields

A Field is built with `::make()` and its handle, which names its column:

| Field | Holds | Column |
| --- | --- | --- |
| `Text` | a single line of plain text | `string` |
| `Textarea` | several lines of plain text | `text` |

Both are Translatable: one value per Locale. `->shared()` keeps one value for the whole Entry; see [Multilingual](multilingual.md).

`->required()` makes a Field required to publish, while a Draft may leave it blank; `->rules([...])` takes any Laravel validation rule, checked on every save. See [Publishing](publishing.md#validation).

`->default($value)` is the value a new Translation stores when the save gives none, and the admin's create form shows it pre-filled. A Shared Field takes it only while the Entry has no Shared value yet, so adding a Locale never overwrites one. An update never applies a default, and a value given as `null` stays `null`:

```php
Text::make('author')->shared()->default('The newsroom'),
```

## The type's table

A type's Field values live in its own table, `kami_type_<handle>`, one row per Locale and one `'*'` row for the Shared Fields (ADR 0006). Until `kami:make:migration` writes it for you, add the migration by hand in `database/migrations`:

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kami_type_article', function (Blueprint $table) {
            $table->foreignUlid('entry_id')->constrained('kami_entries')->cascadeOnDelete();
            $table->string('locale', 20);
            $table->string('summary')->nullable();
            $table->unique(['entry_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kami_type_article');
    }
};
```

One nullable column per Field, with no default: a Draft may be incomplete. A type with no Field needs no table. `kami:install` runs the migration with Kami's own.

## In the admin

Each declared type gets its own item in the panel's navigation, at the plural of its handle: `article` at `/admin/articles`, `news_item` at `/admin/news-items`. Its form is generated from the declaration: title, slug, then each Field, `Text` as a single-line input and `Textarea` as a multi-line one. Publishing from the form is in [Publishing](publishing.md#in-the-admin).

Labels come from the site's `lang/<locale>/kami-types.php`, by handle, and fall back to the humanised handle:

```php
return [
    'article' => [
        'label' => 'Article',
        'plural' => 'Blog posts',
        'fields' => [
            'summary' => ['label' => 'Summary', 'help' => 'Two sentences at most.'],
        ],
    ],
];
```

A User sees a type only with its `view` Permission ([Users and roles](users-and-roles.md#permissions)).

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
