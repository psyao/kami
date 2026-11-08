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
        if (! Sitemap::isStale()) {
            $this->components->info('The sitemap is up to date.');

            return self::SUCCESS;
        }

        $this->components->info('Wrote the sitemap: ' . Sitemap::build() . ' URLs.');

        return self::SUCCESS;
    }
}
