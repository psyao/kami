<?php

declare(strict_types=1);

// The Workbench Site's labels, as a site's lang/en/kami-types.php gives them.
return [
    'article' => [
        'label' => 'Article',
        'plural' => 'Articles',
        'fields' => [
            'summary' => ['label' => 'Summary', 'help' => 'Two sentences at most.'],
            'author' => ['label' => 'Author'],
        ],
    ],
    'page' => [
        'label' => 'Page',
        'plural' => 'Pages',
        'fields' => [
            'body' => ['label' => 'Body'],
        ],
    ],
    'label' => [
        'label' => 'Label',
        'plural' => 'Labels',
        'fields' => [
            'colour' => ['label' => 'Colour'],
        ],
    ],
];
