<?php

declare(strict_types=1);

use Workbench\App\Content\Article;
use Workbench\App\Content\Label;
use Workbench\App\Content\Page;
use Workbench\App\Content\Project;

// The Workbench Site's declarations: two Sites sharing two Locales, so tests never assume a single one.
return [

    'types' => [
        Article::class,
        Page::class,
        Label::class,
        Project::class,
    ],

    'roles' => [
        'administrator' => ['kami.*'],
        'editor' => ['kami.entries.*.view', 'kami.entries.*.create', 'kami.entries.*.update'],
    ],

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
