# Multilingual

A Site publishes in one or more Locales. Each Entry has a Translation per Locale, with its own title, slug and publication, and each Field is either Translatable (one value per Locale) or Shared (one value for the whole Entry). Declaring types is in [Content types](content-types.md), addresses in [Routing and rendering](routing-and-rendering.md).

## Locales

A Site's Locales are listed in `config/kami.php`, the default one first:

```php
'sites' => [
    'default' => [
        'url' => env('APP_URL'),
        'locales' => ['fr', 'en'],
    ],
],
```

The default Locale has no segment of its own; every other Locale answers under its code:

| Locale | Translation with slug `bonjour` / `hello` |
| --- | --- |
| `fr` (default) | `/articles/bonjour` |
| `en` | `/en/articles/hello` |

Each Locale answers only its own slugs: `/en/articles/bonjour` is a 404. Kami makes the page's Locale the application locale, so `__()`, dates and `app()->getLocale()` in a view follow it.

Adding a Locale is one more code in the list. It creates no Translation: nothing answers in that Locale until a Translation is published there, except the Entries of an untranslatable type, which answer there once `kami:install` has run ([Routing and rendering](routing-and-rendering.md#after-a-deploy)).

Removing a Locale deletes nothing: its Translations and Field values are kept, answer nowhere, and come back when the Locale is listed again. `kami:doctor` warns about them. To delete them, see [Schema changes](content-types.md#schema-changes).

## Translations

`SaveEntry` writes one Translation at a time. Its fourth argument is the Locale, the default Locale when left out:

```php
use App\Content\Article;
use Psyao\Kami\Content\Actions\SaveEntry;

$entry = app(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'status' => 'published']);

app(SaveEntry::class)->handle(Article::class, ['title' => 'Hello', 'status' => 'published'], $entry, 'en');
```

Each Translation is published on its own. An Entry with no English Translation, or a Draft or Scheduled one, is absent in English: its English address is a 404 and English queries leave it out.

## Shared and Translatable Fields

`Text` and `Textarea` Fields are Translatable, every other type Shared. `->shared()` makes a Field hold one value for the whole Entry, whatever the Locale; `->translatable()` gives it one value per Locale:

```php
public function fields(): array
{
    return [
        Text::make('summary'),
        Text::make('author')->shared(),
    ];
}
```

`SaveEntry` puts each value in its scope: a Shared value saved with the English Translation is the value every Locale reads.

### Untranslatable types

A type whose content has no language, such as a list of labels, sets `$translatable` to `false`:

```php
final class Label extends ContentType
{
    protected bool $translatable = false;
}
```

All its Fields are Shared, and each Entry has a single Translation, saved with no Locale, that answers in every Locale: `/label/urgent` and `/en/label/urgent`.

## Fallback

A Translatable Field left blank in a Translation reads the default Locale's value, one hop, never through another Locale. It falls back only while the default Locale's Translation is visible: a Draft or Scheduled default Translation gives `null`, so unreviewed text never reaches a published page. Title and slug never fall back.

When a value came from another Locale, `fallbackLocale()` names it, so the view marks its language for screen readers (WCAG 3.1.2):

```blade
<p @if ($lang = $entry->fallbackLocale('summary'))lang="{{ $lang }}"@endif>{{ $entry->summary }}</p>
```

On `/en/articles/hello` with a blank English summary, this prints `<p lang="fr">Un article.</p>`. Fallback costs no query: every Locale's values load with the Entry.

## Reading in a Locale

An Entry reads in the page's Locale. `inLocale()` keeps the Entries visible in another Locale and reads them in it:

```php
Entry::query()->ofType(Article::class)->inLocale('en')->get(); // English Articles, English titles
Entry::query()->inLocale('en')->withUnpublished()->get();      // every Entry with an English Translation
```

`url()` gives an Entry's absolute address, in the Locale it is read in or in the one passed, and `null` when the Entry is not visible there, so a link never leads to a 404:

```php
$entry->url();     // 'https://example.com/articles/bonjour'
$entry->url('en'); // 'https://example.com/en/articles/hello', or null
```

## In the admin

The edit page works on one Translation, named by its URL: `/admin/articles/{id}/edit` for the default Locale, `/admin/articles/{id}/edit/en` for English, as on the front. Its header links to each other Locale: **Edit EN** when that Translation exists, **Add EN** when it does not yet, and saving that page creates it. Each Translation is then published, scheduled or unpublished on its own screen.

Shared Fields are marked as such on every screen and saved once, whichever screen changes them: the English screen's change shows on the French one. A User without the type's `publish` Permission cannot change them while any Translation of the Entry is visible, since visitors would see the change. A Translatable Field left blank shows blank on its screen, not the default Locale's value it falls back to on the front.

An untranslatable type has a single screen, at the default Locale's URL, with no other Locale to reach.

The list shows one state column per Locale: Draft, Scheduled, Published, or Missing when the Entry has no Translation there.
