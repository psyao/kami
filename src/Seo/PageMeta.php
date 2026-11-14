<?php

declare(strict_types=1);

namespace Psyao\Kami\Seo;

use Carbon\CarbonImmutable;

/** What the page's head says to search engines and networks, as `<x-kami::seo />` prints it: read with `Kami::seo()`, as an Inertia site passes it in its props. */
final readonly class PageMeta
{
    /**
     * @param  string  $title  The Meta title, else `kami::seo.title` with the page's title, an Archive's type's plural label, and the Site name, else the Site name alone.
     * @param  string|null  $description  The Meta description, else the type's `summary()`, else the Site's default description on the home page.
     * @param  string|null  $canonical  The page's absolute URL from the declared Site URL, never the request's host, with no query string; `null` on a listing's page reached through a cursor.
     * @param  string|null  $robots  `noindex, nofollow` on a Site that is not indexable, `noindex` for a page Hidden from search engines or a Preview, `noindex, follow` for a listing's page reached through a cursor, `null` otherwise.
     * @param  string|null  $image  The absolute URL of the Social image's `kami-social` Conversion: the Entry's own, else its type's `socialImage()`, else the Site's default; an SVG is skipped.
     * @param  int|null  $imageWidth  The Conversion's width as generated, never upscaled: 1200 at most; set with `image`.
     * @param  int|null  $imageHeight  Its height, 630 at most; set with `image`.
     * @param  string|null  $imageAlt  The image's alt text in the page's Locale, else the default Locale's; `null` for a decorative image or none.
     * @param  string  $ogType  The type's `$ogType`; `website` on a page Kami does not render.
     * @param  CarbonImmutable|null  $publishedTime  For an `article`: its Translation's publication date.
     * @param  CarbonImmutable|null  $modifiedTime  For an `article`: its Entry's last save, in any Locale.
     * @param  array<string, string>  $alternates  hreflang: the URL of each Locale the page is visible in and not Hidden from search engines, by Locale code, then `x-default` to the default Locale's; empty below two Locales, on a hidden page and on a cursor page.
     * @param  string|null  $ogLocale  The page's Locale with its region (`fr_CH`), `null` without one.
     * @param  list<string>  $ogLocaleAlternates  The other Locales of `alternates` that have a region.
     * @param  array<string, string>  $feeds  The Feeds readers discover, by absolute URL in the page's Locale, with their title: the Site's `/feed` on every page once a type offers one, then the type's own on its Archive and Entries.
     */
    public function __construct(
        public string $title,
        public ?string $description,
        public ?string $canonical,
        public ?string $robots,
        public string $siteName,
        public ?string $image = null,
        public ?int $imageWidth = null,
        public ?int $imageHeight = null,
        public ?string $imageAlt = null,
        public string $ogType = 'website',
        public ?CarbonImmutable $publishedTime = null,
        public ?CarbonImmutable $modifiedTime = null,
        public array $alternates = [],
        public ?string $ogLocale = null,
        public array $ogLocaleAlternates = [],
        public array $feeds = [],
    ) {}
}
