<?php

declare(strict_types=1);

namespace Psyao\Kami\Menus\Internal;

use Illuminate\Support\Collection;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Menus\MenuItem;
use Psyao\Kami\Menus\MenuLink;
use Psyao\Kami\Routing\Internal\VisiblePaths;
use Psyao\Kami\Seo\Internal\ResolvePageMeta;
use Psyao\Kami\Sites\Internal\Site;

/**
 * Builds a Menu's visible tree in the current Locale, with its Trail: the items, their Entries and the ancestors of nested ones, read in batches (ADR 0025).
 *
 * @internal
 */
final class BuildMenu
{
    /** The request attribute holding the Menus read in this request, by handle and Locale. */
    public const string MEMO = 'kami.menus';

    /** @var Collection<string, Entry> */
    private Collection $entries;

    /** @var array<string, list<MenuItem>> By Parent id, `''` for the root. */
    private array $children = [];

    private Site $site;

    private string $locale;

    /** The current page's key, null in a Preview. */
    private ?string $current = null;

    /** @return list<MenuLink> */
    public function handle(string $menu): array
    {
        $depth = Menus::depth($menu);
        $this->site = Site::current();
        $this->locale = $this->site->currentLocale();
        // Once per request and Locale, so a Menu rendered twice is read once; `PageCache::invalidate()`, which every write calls, forgets it.
        /** @var array<string, list<MenuLink>> $memo */
        $memo = request()->attributes->get(self::MEMO, []);
        $key = "{$menu}.{$this->locale}";

        if (isset($memo[$key])) {
            return $memo[$key];
        }

        $this->children = [];
        $this->current = request()->attributes->getBoolean('kami.preview') ? null : $this->key(request()->getSchemeAndHttpHost() . request()->getPathInfo());

        $items = MenuItem::query()->where(['site' => $this->site->handle, 'menu' => $menu])->orderBy('position')->orderBy('id')->get();
        $ids = $items->pluck('entry_id')->filter()->unique()->values()->all();
        // Visible ones only, in this Locale: the others hide their items with everything under them.
        $this->entries = $ids === [] ? new Collection : Entry::query()->readIn($this->locale)->where('site', $this->site->handle)->whereIn('id', $ids)->get()->keyBy('id');
        VisiblePaths::preload($this->entries);
        foreach ($items as $item) {
            $this->children[$item->parent_id ?? ''][] = $item;
        }

        $links = $this->links('', $depth);
        request()->attributes->set(self::MEMO, [...$memo, $key => $links]);

        return $links;
    }

    /** @return list<MenuLink> */
    private function links(string $parent, int $levels): array
    {
        $links = [];

        foreach ($levels < 1 ? [] : $this->children[$parent] ?? [] as $item) {
            [$label, $url] = $this->target($item);

            if ($label === null) {
                continue;
            }

            $children = $this->links($item->id, $levels - 1);
            $key = $url === null ? null : $this->key($url);
            $isCurrent = $key !== null && $key === $this->current;
            $inTrail = array_filter($children, fn (MenuLink $child): bool => $child->isCurrent || $child->inTrail) !== []
                || ($key !== null && $this->current !== null && ! $this->isHome($key) && str_starts_with($this->current, "{$key}/"));

            $links[] = new MenuLink($label, $url, $item->new_tab, $children, $isCurrent, $inTrail);
        }

        return $links;
    }

    /**
     * The item's label and address in the current Locale; a null label hides it.
     *
     * @return array{?string, ?string}
     */
    private function target(MenuItem $item): array
    {
        $label = $item->label[$this->locale] ?? null;

        if ($item->entry_id !== null) {
            $entry = $this->entries->get($item->entry_id);
            $url = $entry?->url($this->locale);

            return $url === null ? [null, null] : [$label ?? $entry->title, $url];
        }

        if ($item->archive !== null) {
            $type = resolve(ContentTypes::class)->find($item->archive);

            return $type instanceof ContentType && $type->hasArchive() ? [$label ?? $type->pluralLabel(), ResolvePageMeta::archiveUrls($this->site, $type)[$this->locale]] : [null, null];
        }

        return [$label, $item->url[$this->locale] ?? $item->url[$this->site->defaultLocale()] ?? null];
    }

    /**
     * An address as `host/path`, the host lower-cased, with no scheme or port (ADR 0025 compares host and path), no query, fragment or trailing slash, so two forms of one page compare equal; null for what is never a page: `#…`, `mailto:`, `tel:`.
     */
    private function key(string $url): ?string
    {
        if (str_starts_with($url, '/')) {
            $url = rtrim($this->site->url, '/') . $url;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host']) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)) {
            return null;
        }

        return strtolower($parts['host']) . rtrim(rawurldecode($parts['path'] ?? ''), '/');
    }

    /** The Site's home or a Locale's: never in the Trail by prefix, or every page would be. */
    private function isHome(string $key): bool
    {
        return in_array($key, array_map(fn (string $locale): ?string => $this->key($this->site->url($locale, '')), $this->site->locales), true);
    }
}
