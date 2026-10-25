# Blocks reach Filament's rich editor through a pool of slot classes

Filament's `RichEditor` accepts a custom block only as a `class-string<RichContentCustomBlock>` whose methods are all static. `getId()` takes no argument, and the editor finds a block through a map keyed by each class's `getId()`, so one class can never serve two Blocks (Filament 5.10.1). Kami's Blocks are core classes that never touch Filament (ADR 0007, ADR 0016), so `Admin` needs one Filament class per declared Block.

`Psyao\Kami\Admin\Internal\Blocks` holds 64 one-line classes, `Slot01` to `Slot64`, each a `final` subclass of an internal `BlockSlot`. At boot, `KamiPlugin` gives each declared Block a slot, and the slot reads its Block from a static property: `getId()` returns the Block's handle, and the label, modal and preview come from the Block. There is no generated code, no `eval()` and no file written. The ceiling is 64 Blocks per installation; a 65th is a boot error that says so. Slots may be reassigned between deploys, because stored nodes carry the Block's handle, never the slot.

## Considered options

- **A Kami `Block` extending `RichContentCustomBlock`** (Velox). This ties the core to Filament, against ADR 0011.
- **Classes generated into `bootstrap/cache/kami-blocks.php`** when the list of Blocks changes, like `config:cache`. This needs a cache to invalidate and a writable directory at first render. It stays the upgrade path if 64 Blocks ever fall short.
- **`eval()` of generated classes at boot.** Nothing to cache, but it is a security red flag (principle 1).
- **One Filament block with a Block picker inside its modal.** One class is enough, but editors get a single "Block" button instead of one per Block, with its label and icon.

## Consequences

- `BlockSlot` carries a `ponytail:` comment naming the ceiling and the upgrade path.
- A Filament change to `RichContentCustomBlock` touches `BlockSlot` only.
