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
            'website' => ['label' => 'Site web'],
            'contact' => ['label' => 'Contact'],
            'pages' => ['label' => 'Pages'],
            'rating' => ['label' => 'Note'],
            'starts_on' => ['label' => 'Commence le'],
            'starts_at' => ['label' => 'Commence à'],
            'kind' => ['label' => 'Genre', 'options' => ['news' => 'Actualité', 'review' => 'Critique']],
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
