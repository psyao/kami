<?php

declare(strict_types=1);

// The starter kit's public/index.php up to Kami's line, over the test's storage/; what follows stands in for Laravel's boot.
$storage = (string) getenv('KAMI_STORAGE');

if (file_exists($maintenance = $storage . '/framework/maintenance.php')) {
    require $maintenance;
}

(require __DIR__ . '/../../../resources/page-cache.php')($storage);

echo 'Laravel booted';
