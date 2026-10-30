<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

// Fails on the release pull request, which is where the manifest takes the new version.
it('has a section in UPGRADING.md for the current breaking release', function (): void {
    $root = dirname(__DIR__, 2);
    /** @var array{'.': string} $manifest */
    $manifest = File::json($root . '/.release-please-manifest.json');
    [$major, $minor] = explode('.', $manifest['.']);

    // 0.x breaks on the minor, later versions on the major (UPGRADING.md).
    $breaking = $major === '0' ? "0.{$minor}.0" : "{$major}.0.0";

    expect(File::get($root . '/UPGRADING.md'))->toContain("\n## {$breaking}\n");
});

it('installs released versions in the guides', function (): void {
    foreach (File::allFiles(dirname(__DIR__, 2) . '/docs/guide') as $guide) {
        expect($guide->getContents())->not->toContain('dev-main', $guide->getFilename());
    }
});
