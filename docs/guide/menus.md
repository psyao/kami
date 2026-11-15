# Menus

A Menu is a place in your layout, such as the main navigation or the footer's links. You declare it in code; editors fill it with links to Entries, Archives or addresses; your layout renders it with `<x-kami::menu>`, the current page and its Trail marked (ADR 0025).

## Declaring a Menu

List each Menu under `menus` in `config/kami.php`, by handle, with the `depth` its items may nest to, from 1 to 3 (1 when left out):

```php
'menus' => [
    'primary' => ['depth' => 2],
    'footer' => [],
],
```

There is no table of Menus and nothing to install: a declaration holds for every Site, and its items belong to one. A handle removed from the config keeps its items, unseen until it is declared again. Lowering `depth` hides the deeper items and breaks no view.

Name each Menu in `lang/<locale>/kami-menus.php`. The label is the admin's, and the `<nav>`'s `aria-label`, which screen readers announce:

```php
return [
    'primary' => ['label' => 'Main menu'],
    'footer' => ['label' => 'Footer menu'],
];
```

A missing label falls back to the handle in sentence case: `primary` reads `Primary`.

## Items

One Menu holds one tree of items for all the Site's Locales. An item leads to one of three targets:

- **An Entry**, of a type with an address. A Draft may be linked: the item stays hidden until the Entry is published.
- **An Archive**, by its type's handle: the list of that type's Entries at its Prefix ([Archives](content-types.md#archives)).
- **An address**, typed: `https://…`, `http://…`, a path on the Site such as `/contact` (but not `//…`), a fragment such as `#content`, `mailto:` or `tel:`. Anything else, `javascript:` for one, is refused.

Its label and its address are given per Locale:

- A missing label falls back to the target's title in that Locale: the Entry's title, or the type's plural label for an Archive. It never falls back to another Locale's label.
- A missing address falls back to the default Locale's.

An item is hidden, with everything under it, in a Locale where its target is not visible: an Entry with no published Translation there, in the Trash, or under a hidden ancestor; an Archive whose type no longer has `$archive`; an address item with no label in that Locale.

`new_tab` opens the link in a new tab, with `rel="noopener"`.

## Rendering a Menu

Put the component in your layout:

```blade
<x-kami::menu handle="primary" />
```

It renders a labelled `<nav>` and nested lists, and nothing for a Menu with no visible item:

```html
<nav class="kami-menu" aria-label="Main menu">
    <ul class="kami-menu-list">
        <li class="kami-menu-item">
            <a href="https://example.com/about" aria-current="true">About</a>
            <ul class="kami-menu-list">
                <li class="kami-menu-item"><a href="https://example.com/about/team" aria-current="page">Team</a></li>
            </ul>
        </li>
        <li class="kami-menu-item"><a href="https://shop.example.com" target="_blank" rel="noopener">Shop</a></li>
    </ul>
</nav>
```

`aria-current="page"` marks the current page, `aria-current="true"` the rest of its Trail: style both with `[aria-current]` selectors. Attributes you pass, a `class` for one, merge onto the `<nav>`. The component ships no styles: give each link a target of at least 24 px, such as `.kami-menu a { display: inline-block; padding-block: 0.25rem; }`, for WCAG 2.5.8. There is no dropdown and no script: opening a submenu is your layout's.

### The Trail

The Trail is read from addresses, not from the tree:

- An item is **current** when its address is the current page: same host and path, the query string and a trailing slash ignored.
- An item is **in the Trail** when an item under it is current, or when the current path sits under its path, segment by segment: `/photos` is in the Trail of `/photos/wedding`, not of `/photos-old`.

So one rule marks an Entry's ancestors, an Archive on its Entries' pages, and an item whose page is not the current page's Parent at all. The Site's home and each Locale's (`/`, `/en`), other hosts, fragments, `mailto:` and `tel:` are never in the Trail by prefix. Nothing is current in a Preview.

### Your own markup

When the component does not fit, `Kami::menu()` returns the same tree as `Psyao\Kami\Menus\MenuLink` values, for the current Locale:

```blade
@foreach (Kami::menu('primary') as $link)
    <a href="{{ $link->url }}" @class(['active' => $link->isCurrent || $link->inTrail])>{{ $link->label }}</a>
    {{-- $link->children: the visible links under it; $link->newTab --}}
@endforeach
```

`url` is nullable: an item with no address renders as text, which the component does with a `<span>`. An undeclared handle throws an `InvalidArgumentException`.

A Menu is read in a few queries once per request and Locale, so the component and your own calls share them: the items, their Entries with their Translations and Field values, and the ancestors of nested Entries. It has no cache of its own: the [page cache](page-cache.md) stores the page around it, and every item write makes it stale, since every page may show a Menu. A write in the same request reads the Menu again; after a change Kami cannot see, such as a config set at runtime, call `Kami::flushCache()`.

## Writing items

Editors write items in the admin, under `kami.menus.manage` ([Users and Roles](users-and-roles.md#permissions)). Your seeders and importers write them through the same three actions, which do not authorise: code calling them from a request checks `kami.menus.manage` first.

```php
use Psyao\Kami\Menus\Actions\DeleteMenuItem;
use Psyao\Kami\Menus\Actions\MoveMenuItem;
use Psyao\Kami\Menus\Actions\SaveMenuItem;

$save = resolve(SaveMenuItem::class);

$about = $save->handle('primary', ['entry' => $aboutPage]);
$save->handle('primary', ['entry' => $teamPage, 'parent' => $about->id]);
$save->handle('primary', ['archive' => 'article', 'label' => ['fr' => 'Articles', 'en' => 'Articles']]);
$shop = $save->handle('primary', ['url' => 'https://shop.example.com', 'label' => ['fr' => 'Boutique', 'en' => 'Shop'], 'new_tab' => true]);

resolve(MoveMenuItem::class)->handle($shop, 1);
resolve(DeleteMenuItem::class)->handle($about);
```

`SaveMenuItem::handle($menu, $values, $item = null, $actor = null)` creates an item, last among its siblings, or changes the one given:

- `parent`: an item id, or null for the root. An item changing Parent goes last among its new siblings.
- One target: `entry` (an `Entry` or its id), `archive` (a type handle) or `url`. Giving one clears the others.
- `label` and `url`: by Locale code. They merge with what the item holds, and `''` or null clears a Locale. A string `url` is the default Locale's.
- `new_tab`.

A key left out keeps its stored value. The item you pass takes the saved values; a refusal leaves it as it was. It refuses with a `ValidationException`, changing nothing:

- on `target`, with no target or several;
- on `entry`, for an Entry of another Site, in the Trash, or of a type with no address;
- on `archive`, for a type with no Archive;
- on `url.<locale>`, for an address outside the list above, a backslash included, or with no address in the default Locale;
- on `parent`, for a Parent from another Menu, the item itself or one of its descendants, or a place deeper than the Menu's `depth`, counting the item's own descendants. These are checked when the item is created or changes Parent only: an item a lowered `depth` left too deep stays editable.

An undeclared Menu, a Locale the Site does not declare, or an item of another Menu throws an `InvalidArgumentException`.

`MoveMenuItem::handle($item, $position, $actor = null)` gives the item that rank among its siblings, from 1, renumbering the others; a rank out of range is brought back within it. The admin's up and down actions pass the rank above or below. Its Parent changes through `SaveMenuItem`.

`DeleteMenuItem::handle($item, $actor = null)` deletes the item. Its children move up one level, in its place and in their order, so no link goes with it silently.

Deleting an Entry for good ([`DeleteEntry`](content-types.md#the-trash)) deletes its items first, through `DeleteMenuItem`, and the admin's confirmation names the Menus that lose a link. An Entry in the Trash keeps its items, hidden until it is restored.

Each action runs in one transaction and records `kami.menu-items.created`, `updated`, `moved` or `deleted` in the [Audit log](audit-log.md). Once committed, it dispatches `Psyao\Kami\Menus\Events\MenuItemSaved` (`item`, `created`, `actor`), `MenuItemMoved` (`item`, `actor`; a move that changes nothing dispatches nothing) or `MenuItemDeleted` (`itemId`, `menu`, `site`, `actor`).

## Shared hosting and server

Both behave the same: a Menu is read in a few indexed queries during the request, and writes touch nothing but the database.
