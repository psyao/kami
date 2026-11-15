<?php

declare(strict_types=1);

namespace Psyao\Kami\Menus;

/**
 * One visible link of a Menu, in the current Locale, as `Kami::menu()` returns it for a site's own markup (ADR 0025).
 */
final readonly class MenuLink
{
    /**
     * @param  string|null  $url  Null for an item with no address, which V1's label-only items bring.
     * @param  list<MenuLink>  $children  The visible ones, within the Menu's `depth`.
     * @param  bool  $isCurrent  Its address is the current page's: same host and path, the query string ignored. Never in a Preview.
     * @param  bool  $inTrail  A link under it is current, or the current path sits under its path, segment by segment.
     */
    public function __construct(
        public string $label,
        public ?string $url,
        public bool $newTab,
        public array $children,
        public bool $isCurrent,
        public bool $inTrail,
    ) {}
}
