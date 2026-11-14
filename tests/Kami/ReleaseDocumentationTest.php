<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

// #604: tickets write under `## Unreleased`, which the release pull request renames to its version, putting an empty one back
// above it. The manifest takes the new version on that pull request alone, so the test fails there until the rename.
it('has an Unreleased section above a section for the current release', function (): void {
    $root = dirname(__DIR__, 2);
    /** @var array{'.': string} $manifest */
    $manifest = File::json($root . '/.release-please-manifest.json');
    preg_match_all('/^## (.+)$/m', File::get($root . '/UPGRADING.md'), $headings);

    expect($headings[1][0] ?? null)->toBe('Unreleased')
        ->and($headings[1])->toContain($manifest['.']);
});

it('installs released versions in the guides', function (): void {
    foreach (File::allFiles(dirname(__DIR__, 2) . '/docs/guide') as $guide) {
        expect($guide->getContents())->not->toContain('dev-main', $guide->getFilename());
    }
});
