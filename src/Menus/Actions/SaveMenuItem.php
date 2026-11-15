<?php

declare(strict_types=1);

namespace Psyao\Kami\Menus\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Psyao\Kami\Audit\Actions\RecordAuditEvent;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Menus\Events\MenuItemSaved;
use Psyao\Kami\Menus\Internal\Menus;
use Psyao\Kami\Menus\Internal\MenuTree;
use Psyao\Kami\Menus\MenuItem;
use Psyao\Kami\Sites\Internal\Site;

/**
 * Creates or changes a Menu item of the current Site: its target, Parent, labels, addresses and `new_tab`. The one way items are written, from the admin, seeders or imports alike (ADR 0025). It does not authorise: the caller checks the actor's `kami.menus.manage` Permission first (ADR 0011).
 *
 * Refusals throw Laravel's `ValidationException`, changing nothing: on `target` with no target or several, on `entry`, `archive` or `url` for a target that cannot be linked, on `parent` for a Parent from another Menu, under the item itself, or nesting deeper than the Menu's `depth`.
 */
final class SaveMenuItem
{
    private const array KEYS = ['parent', 'entry', 'archive', 'url', 'label', 'new_tab'];

    private const array TARGETS = ['entry', 'archive', 'url'];

    /** `https://`, `http://`, a path on the Site but not `//…`, a fragment, `mailto:` and `tel:`, with no whitespace. */
    private const string ALLOWED_ADDRESS = '~^(?:https?://[^\s/?#]+[^\s]*|/(?!/)[^\s]*|#[^\s]*|mailto:[^\s]+|tel:[^\s]+)$~i';

    /**
     * @param  string  $menu  A handle declared under `kami.menus`.
     * @param  array<string, mixed>  $values  `parent`: an item id, or null for the root. One target: `entry` (an Entry or its id), `archive` (a type handle) or `url`. `label` and `url`: by Locale code, merged with the stored ones, `''` or null clearing a Locale; a string `url` is the default Locale's. `new_tab`. A key left out keeps its stored value; giving a target clears the others.
     * @param  MenuItem|null  $item  The item to change; a new one, last among its siblings, when null. One changing Parent goes last among its new siblings.
     * @param  Authenticatable|null  $actor  Null for imports and the CLI.
     *
     * @throws InvalidArgumentException For a Menu not declared, an item from another Menu, or an undeclared Locale.
     * @throws ValidationException
     */
    public function handle(string $menu, array $values, ?MenuItem $item = null, ?Authenticatable $actor = null): MenuItem
    {
        $site = Site::current();
        $depth = Menus::depth($menu);

        if ($item instanceof MenuItem && ($item->menu !== $menu || $item->site !== $site->handle)) {
            throw new InvalidArgumentException("The Menu item [{$item->id}] is not in the Menu [{$menu}] of Site [{$site->handle}].");
        }

        if (is_string($values['url'] ?? null)) {
            $values['url'] = [$site->defaultLocale() => $values['url']];
        }

        if (($values['entry'] ?? null) instanceof Entry) {
            $values['entry'] = $values['entry']->id;
        }

        Validator::make($values, [
            'parent' => ['nullable', 'string'],
            'entry' => ['nullable', 'string'],
            'archive' => ['nullable', 'string'],
            'url' => ['nullable', 'array'],
            'url.*' => ['nullable', 'string', 'max:2048'],
            'label' => ['nullable', 'array'],
            'label.*' => ['nullable', 'string', 'max:255'],
            'new_tab' => ['boolean'],
            ...array_fill_keys(array_keys(Arr::except($values, self::KEYS)), 'prohibited'),
        ])->validate();

        /** @var array{label?: array<string, string|null>|null, url?: array<string, string|null>|null} $maps Validated above. */
        $maps = Arr::only($values, ['label', 'url']);

        foreach ($maps as $map) {
            $unknown = array_diff(array_keys($map ?? []), $site->locales);

            if ($unknown !== []) {
                throw new InvalidArgumentException('No Locale [' . implode(', ', $unknown) . "] is declared in Site [{$site->handle}].");
            }
        }

        // Changes go on a copy, so a refusal leaves the caller's item as it was; it takes them once saved.
        $original = $item;
        $item = $item instanceof MenuItem ? clone $item : new MenuItem(['site' => $site->handle, 'menu' => $menu, 'new_tab' => false]);
        $given = array_filter(Arr::only($values, self::TARGETS), fn (mixed $value): bool => $value !== null && $value !== [] && array_filter((array) $value, fn (mixed $part): bool => $part !== null && $part !== '') !== []);

        if (count($given) > 1) {
            throw ValidationException::withMessages(['target' => __('kami::admin.menus.target')]);
        }

        // Giving a target clears the others; `url` then merges Locale by Locale with what an address item already holds.
        if ($given !== []) {
            foreach (array_diff(self::TARGETS, array_keys($given)) as $other) {
                $item->setAttribute($other === 'entry' ? 'entry_id' : $other, null);
            }
        }

        $item->entry_id = array_key_exists('entry', $values) ? $this->filled($values['entry']) : $item->entry_id;
        $item->archive = array_key_exists('archive', $values) ? $this->filled($values['archive']) : $item->archive;

        foreach ($maps as $key => $map) {
            /** @var array<string, string> $stored */
            $stored = $item->getAttribute($key) ?? [];
            $merged = [...$stored, ...$map ?? []];
            $merged = array_filter($merged, fn (?string $value): bool => $value !== null && trim($value) !== '');
            $item->setAttribute($key, $merged === [] ? null : array_map(trim(...), $merged));
        }

        if (array_key_exists('new_tab', $values)) {
            $item->new_tab = (bool) $values['new_tab'];
        }

        $this->checkTarget($item, $site);

        $created = ! $item->exists;

        DB::transaction(function () use ($item, $values, $depth, $created, $actor): void {
            // ponytail: no lock on the Menu's tree, so two saves at once could nest a pair under each other; V1 locks the tree if a friction shows it.
            $tree = MenuTree::of($item->site, $item->menu);
            $parent = array_key_exists('parent', $values) ? (is_string($values['parent']) ? $values['parent'] : null) : $item->parent_id;

            // Checked where the item goes only: an item left too deep by a lowered `depth` stays editable, hidden on the front.
            if ($created || $parent !== $item->parent_id) {
                if ($parent !== null && ! $tree->has($parent)) {
                    throw ValidationException::withMessages(['parent' => __('kami::admin.menus.parent_elsewhere')]);
                }

                if ($parent !== null && $item->exists && in_array($item->id, [$parent, ...$tree->ancestors($parent)], true)) {
                    throw ValidationException::withMessages(['parent' => __('kami::admin.menus.parent_inside')]);
                }

                $level = $parent === null ? 1 : count($tree->ancestors($parent)) + 2;
                $height = $item->exists ? $tree->height($item->id) : 1;

                if ($level + $height - 1 > $depth) {
                    throw ValidationException::withMessages(['parent' => trans_choice('kami::admin.menus.depth', $depth, ['depth' => $depth])]);
                }

                $item->parent_id = $parent;
                $item->position = $tree->nextPosition($parent);
            }

            $item->save();
            resolve(RecordAuditEvent::class)->handle($created ? 'kami.menu-items.created' : 'kami.menu-items.updated', $item, ['menu' => $item->menu], $actor);
        });

        if ($original instanceof MenuItem) {
            $original->setRawAttributes($item->getAttributes(), true);
            $item = $original;
        }

        event(new MenuItemSaved($item, $created, $actor));

        return $item;
    }

    private function filled(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @throws ValidationException */
    private function checkTarget(MenuItem $item, Site $site): void
    {
        $targets = array_filter([$item->entry_id, $item->archive, $item->url]);

        if (count($targets) !== 1) {
            throw ValidationException::withMessages(['target' => __('kami::admin.menus.target')]);
        }

        if ($item->entry_id !== null) {
            // A Draft may be linked, hidden until published; one in the Trash, of another Site or with no address may not.
            $entry = Entry::query()->withUnpublished()->where('site', $site->handle)->find($item->entry_id);

            if (! $entry instanceof Entry || $entry->contentType()?->isRoutable() !== true) {
                throw ValidationException::withMessages(['entry' => __('kami::admin.menus.entry')]);
            }
        }

        if ($item->archive !== null && resolve(ContentTypes::class)->find($item->archive)?->hasArchive() !== true) {
            throw ValidationException::withMessages(['archive' => __('kami::admin.menus.archive')]);
        }

        if ($item->url !== null) {
            foreach ($item->url as $locale => $address) {
                // A backslash too: browsers read `/\host` as `//host`.
                if (preg_match(self::ALLOWED_ADDRESS, $address) !== 1 || str_contains($address, '\\')) {
                    throw ValidationException::withMessages(["url.{$locale}" => __('kami::admin.menus.url')]);
                }
            }

            if (! isset($item->url[$site->defaultLocale()])) {
                throw ValidationException::withMessages(["url.{$site->defaultLocale()}" => __('kami::admin.menus.url_default', ['locale' => strtoupper($site->defaultLocale())])]);
            }
        }
    }
}
