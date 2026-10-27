<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

// Tests are left out: Rector never rewrites an assertion.
return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/config',
        __DIR__ . '/src',
        __DIR__ . '/workbench/app',
    ])
    ->withSets([__DIR__ . '/rector-set.php']);
