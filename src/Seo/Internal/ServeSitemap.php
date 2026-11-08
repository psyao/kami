<?php

declare(strict_types=1);

namespace Psyao\Kami\Seo\Internal;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Reached only through the `!-f` rewrite, when a sitemap file is missing: builds them all, then serves the one asked for (ADR 0027).
 *
 * @internal
 */
final readonly class ServeSitemap
{
    public function __invoke(?string $file = null): BinaryFileResponse
    {
        $path = public_path($file === null ? 'sitemap.xml' : "sitemaps/{$file}");

        // A built sitemap without that file has nothing more to give: one build per missing sitemap, never one per request.
        if (! is_file($path) && ($file === null || ! is_dir(public_path('sitemaps')))) {
            Sitemap::build();
        }

        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Type' => 'application/xml']);
    }
}
