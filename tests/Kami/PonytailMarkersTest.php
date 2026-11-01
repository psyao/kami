<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

// CLAUDE.md: a marker names what lifts it, here or on the line under it.
it('names what lifts every ponytail marker', function (): void {
    $root = dirname(__DIR__, 2);
    $unnamed = [];

    foreach (['src', 'database', 'tests', '.github'] as $directory) {
        foreach (File::allFiles("{$root}/{$directory}", hidden: true) as $file) {
            if (! in_array($file->getExtension(), ['php', 'yml'], true)) {
                continue;
            }

            $lines = file($file->getPathname()) ?: [];

            foreach ($lines as $number => $line) {
                if (preg_match('~(#|//|\*) ponytail:~', $line) !== 1) {
                    continue;
                }

                if (preg_match('~\bM\d+\b|\bV1\b|#\d+|next release~', $line . ($lines[$number + 1] ?? '')) !== 1) {
                    $unnamed[] = "{$file->getRelativePathname()}:" . ($number + 1);
                }
            }
        }
    }

    expect($unnamed)->toBe([]);
});
