<?php

declare(strict_types=1);

namespace Psyao\Kami\Seo\Internal;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Rebuilds the sitemap when it is stale, and does nothing otherwise, so it can run every hour (ADR 0027).
 *
 * @internal
 */
#[Description('Rebuild the sitemap when it is stale')]
#[Signature('kami:sitemap')]
final class SitemapCommand extends Command
{
    public function handle(): int
    {
        $this->components->info(Sitemap::isStale() ? 'Wrote the sitemap: ' . Sitemap::build() . ' URLs.' : 'The sitemap is up to date.');

        return self::SUCCESS;
    }
}
