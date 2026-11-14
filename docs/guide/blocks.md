# Blocks

A Block is anything other than text inside a `RichText` document: a highlighted note, an image, a video. You declare it as a class with Kami Fields and a Blade view. Editors insert it from the rich editor's toolbar and edit its Fields in a modal. Kami validates its data on save, records what it links, and renders it through your view (ADR 0016, ADR 0024).

## Declaring a Block

A Block is a `final` class extending `Psyao\Kami\Content\Blocks\Block`:

```php
namespace App\Blocks;

use Psyao\Kami\Content\Blocks\Block;
use Psyao\Kami\Content\Fields\Media;
use Psyao\Kami\Content\Fields\Select;
use Psyao\Kami\Content\Fields\Text;

final class Callout extends Block
{
    public function handle(): string
    {
        return 'callout';
    }

    public function fields(): array
    {
        return [
            Text::make('text')->required(),
            Select::make('tone')->options(['info', 'warning']),
            Media::make('image')->images(),
        ];
    }

    public function view(): string
    {
        return 'blocks.callout';
    }

    public function summary(array $data): ?string
    {
        return is_string($data['text'] ?? null) ? $data['text'] : null;
    }
}
```

List it in `config/kami.php`, then offer it on a `RichText` Field:

```php
'blocks' => [
    App\Blocks\Callout::class,
],
```

```php
RichText::make('body')->blocks([Callout::class]),
```

The handle is stored in every document holding the Block: renaming it means migrating those documents. Labels come from `lang/<locale>/kami-blocks.php`, by handle, as types' come from `kami-types.php`:

```php
return [
    'callout' => [
        'label' => 'Callout',
        'fields' => [
            'text' => ['label' => 'Text', 'help' => 'One sentence.'],
            'tone' => ['label' => 'Tone', 'options' => ['info' => 'Information', 'warning' => 'Warning']],
        ],
    ],
];
```

### Fields

A Block takes any Field type a Content Type takes, Media, Entries and Terms included. It differs in a few ways:

- A Block's Fields follow its document: a Block in a Translatable `RichText` holds one set of values per Locale, whatever `->shared()` says.
- `->required()` is checked when the Entry is published, as for a type's Field. Every other rule applies on every save, Drafts included.
- A `RichText` inside a Block takes no `->blocks()`: Blocks do not nest.
- A condition, `filterable()`, `sortable()` and a default have no meaning inside a document.

The first read of the declarations refuses each of these, a Block that `kami.blocks` does not list, a handle that is not snake_case or is taken twice, and a Field handle that appears twice.

## In the editor

The rich editor's Blocks button lists the Field's Blocks by label. Inserting one opens its modal, built from its Fields as the Entry form builds a type's, with the same components. A Block with no Fields goes straight in. A Block in the document shows its label and its `summary()`, which Kami escapes. Its front view is never shown in the admin, where your site's CSS is not loaded. Clicking a Block opens its modal again.

An admin installation reaches 64 Blocks at most: Filament's editor needs one class per Block, and Kami ships 64 (ADR 0024). A 65th listed in `kami.blocks` is an error on the panel.

## Saving

`SaveEntry` normalises a Block like any document. An offered Block keeps its declared Fields only, each normalised as a type's Field is: a forged key goes, and a single Media id becomes a list. Its errors are keyed `body.blocks.{n}.{field}`, with `n` counting every Block of the document from 0, and their message names the Block: "The text in Callout 2 field is required." The admin shows them on the editor.

A Block's Media items and Entries are recorded in `kami_references` under the `RichText`'s handle, in its Locale. "What uses this?" lists the Entry, and a Media item a Block shows cannot be deleted. A Block that links a Term does not list the Entry in that Term's Archive or its `entries()`: only a relation Field classifies an Entry.

## Rendering

`{{ $entry->body }}` renders each Block through its view, given its data by Field handle and typed as an Entry reads a Field:

- a Media Field: a `Media` or `null`, a Collection with `->multiple()`;
- an Entries or Terms Field: a Collection of the Entries visible in the page's Locale, in pick order;
- a `RichText`: a `Document`;
- any other Field: the value its cast gives, such as a `CarbonImmutable` for a Date.

```blade
<aside class="callout callout-{{ $tone ?? 'info' }}">
    <x-kami::image :media="$image" preset="card" />
    <p>{{ $text }}</p>
</aside>
```

A document's Blocks read their Media items and Entries in one batch, one query each with their Translations, whatever the number of Blocks.

## An unavailable Block

A Block whose class is gone from `kami.blocks`, or that its Field no longer offers, stays in its document as it is: Kami never drops it. `SaveEntry` keeps it untouched, neither validated nor recorded. It renders nothing. The editor shows it as unavailable, under its handle: an editor can only delete it. Declaring or offering it again brings it back.

## Shared hosting and a server

No difference: validation and rendering are plain PHP in the request, with a few indexed queries and no process, network or queue.
