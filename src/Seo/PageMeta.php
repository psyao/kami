<?php

declare(strict_types=1);

namespace Psyao\Kami\Seo;

use Carbon\CarbonImmutable;

/** What the page's head says to search engines and networks, as `<x-kami::seo />` prints it: read with `Kami::seo()`, as an Inertia site passes it in its props. */
final readonly class PageMeta
{
    /**
     * @param  string  $title  The Meta title, else `kami::seo.title` with the page's title and the Site name, else the Site name alone.
     * @param  string|null  $description  The Meta description, else the type's `summary()`, else the Site's default description on the home page.
     * @param  string|null  $canonical  The page's absolute URL from the declared Site URL, never the request's host, with no query string.
     * @param  string|null  $robots  `noindex, nofollow` on a Site that is not indexable, `noindex` for a page Hidden from search engines or a Preview, `null` otherwise.
     * @param  string|null  $image  The absolute URL of the Social image's `kami-social` Conversion: the Entry's own, else its type's `socialImage()`, else the Site's default; an SVG is skipped.
     * @param  int|null  $imageWidth  The Conversion's width as generated, never upscaled: 1200 at most; set with `image`.
     * @param  int|null  $imageHeight  Its height, 630 at most; set with `image`.
     * @param  string|null  $imageAlt  The image's alt text in the page's Locale, else the default Locale's; `null` for a decorative image or none.
     * @param  string  $ogType  The type's `$ogType`; `website` on a page Kami does not render.
     * @param  CarbonImmutable|null  $publishedTime  For an `article`: its Translation's publication date.
     * @param  CarbonImmutable|null  $modifiedTime  For an `article`: its Entry's last save, in any Locale.
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
    ) {}
}
