<?php

declare(strict_types=1);

// The Workbench Site's labels, as a site's lang/fr/kami-types.php gives them.
return [
    'article' => [
        'label' => 'Article',
        'plural' => 'Articles',
        'fields' => [
            'summary' => ['label' => 'Résumé', 'help' => 'Deux phrases au plus.'],
            'author' => ['label' => 'Auteur'],
            'featured' => ['label' => 'À la une'],
            'accent' => ['label' => 'Accent'],
        ],
    ],
    'page' => [
        'label' => 'Page',
        'plural' => 'Pages',
        'fields' => [
            'body' => ['label' => 'Corps'],
        ],
    ],
    'label' => [
        'label' => 'Étiquette',
        'plural' => 'Étiquettes',
        'fields' => [
            'colour' => ['label' => 'Couleur'],
        ],
    ],
];
