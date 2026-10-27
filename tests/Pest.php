<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Psyao\Kami\Tests\Install\InstallTestCase;
use Psyao\Kami\Tests\TestCase;

// One directory per suite: `Install/` boots without the Workbench.
pest()->extend(TestCase::class)->in('Kami', 'Volume');
// The browser loads Filament's scripts and Kami's stylesheet from the skeleton's public directory, as on a Site after `kami:install`.
pest()->extend(TestCase::class)->beforeEach(fn () => $this->artisan('filament:assets'))->in('Browser');
pest()->extend(InstallTestCase::class)->in('Install');

/**
 * The anchors of a Markdown file's headings, as GitHub and the Boost resources link them.
 *
 * @return Collection<int, string>
 */
function headingAnchors(string $file): Collection
{
    return Str::of(File::get(dirname(__DIR__) . "/{$file}"))
        ->matchAll('/^#+ (.+)$/m')
        ->map(fn (string $heading): string => Str::slug($heading));
}
