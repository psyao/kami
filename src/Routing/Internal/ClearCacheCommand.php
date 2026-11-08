<?php

declare(strict_types=1);

namespace Psyao\Kami\Routing\Internal;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Makes every cached page and fragment stale and deletes every stored page (ADR 0021); `optimize:clear` runs it too.
 *
 * @internal
 */
#[Description('Make every cached page and fragment stale, and delete the stored pages')]
#[Signature('kami:cache:clear')]
final class ClearCacheCommand extends Command
{
    public function handle(): int
    {
        PageCache::invalidate();
        PageCache::prune();

        $this->components->info('Kami\'s page cache and fragments are cleared.');

        return self::SUCCESS;
    }
}
