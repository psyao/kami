<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\Name\RenameClassRector;

// Tests are left out: Rector never rewrites an assertion.
return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/config',
        __DIR__ . '/database',
        __DIR__ . '/src',
        __DIR__ . '/workbench/app',
        __DIR__ . '/workbench/database',
    ])
    ->withSets([__DIR__ . '/rector-set.php'])
    // Laravel's own validator holds the parsed rules its contract lacks, which the re-rendered form shapes its old input by.
    ->withSkip([RenameClassRector::class => [__DIR__ . '/src/Forms/Internal/SubmitForm.php']]);
