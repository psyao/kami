<?php

declare(strict_types=1);

// The Workbench Site's declarations: two Sites sharing two Locales, so tests never assume a single one.
return [

    'sites' => [
        'default' => [
            'url' => 'http://localhost',
            'locales' => ['fr', 'en'],
            'timezone' => 'Europe/Zurich',
        ],
        'blog' => [
            'url' => 'http://blog.localhost',
            'locales' => ['en'],
            'timezone' => 'Europe/London',
        ],
    ],

];
