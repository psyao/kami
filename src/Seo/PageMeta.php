<?php

declare(strict_types=1);

namespace Psyao\Kami\Seo;

/** What the page's head says to search engines and networks, as `<x-kami::seo />` prints it: read with `Kami::seo()`, as an Inertia site passes it in its props. */
final readonly class PageMeta
{
    /**
     * @param  string  $title  The Meta title, else `kami::seo.title` with the page's title and the Site name, else the Site name alone.
     * @param  string|null  $description  The Meta description, else the type's `summary()`, else the Site's default description on the home page.
     * @param  string|null  $canonical  The page's absolute URL from the declared Site URL, never the request's host, with no query string.
     * @param  string|null  $robots  `noindex, nofollow` on a Site that is not indexable, `noindex` for a page Hidden from search engines or a Preview, `null` otherwise.
     */
    public function __construct(
        public string $title,
        public ?string $description,
        public ?string $canonical,
        public ?string $robots,
        public string $siteName,
    ) {}
}
