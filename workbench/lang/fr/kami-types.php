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
    'home' => [
        'label' => 'Accueil',
        'fields' => [
            'intro' => ['label' => 'Introduction'],
        ],
    ],
    'testimonial' => [
        'label' => 'Témoignage',
        'plural' => 'Témoignages',
        'fields' => [
            'quote' => ['label' => 'Citation'],
        ],
    ],
    'label' => [
        'label' => 'Étiquette',
        'plural' => 'Étiquettes',
        'fields' => [
            'colour' => ['label' => 'Couleur'],
        ],
    ],
    'project' => [
        'label' => 'Projet',
        'plural' => 'Projets',
        'tabs' => [
            'details' => ['label' => 'Détails'],
            'story' => ['label' => 'Récit'],
        ],
        'sections' => [
            'highlight' => ['label' => 'Mise en avant'],
        ],
        'fields' => [
            'client' => ['label' => 'Client'],
            'featured' => ['label' => 'À la une'],
            'featured_note' => ['label' => 'Pourquoi à la une'],
            'kind' => ['label' => 'Genre', 'options' => ['news' => 'Actualité', 'review' => 'Critique', 'essay' => 'Essai']],
            'source' => ['label' => 'Source'],
            'story' => ['label' => 'Récit'],
            'launched_on' => ['label' => 'Lancé le'],
            'links' => ['label' => 'Liens', 'fields' => [
                'label' => ['label' => 'Libellé'],
                'url' => ['label' => 'Adresse'],
            ]],
            'address' => ['label' => 'Adresse', 'fields' => [
                'street' => ['label' => 'Rue'],
                'city' => ['label' => 'Ville'],
            ]],
        ],
    ],
];
