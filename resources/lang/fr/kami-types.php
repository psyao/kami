<?php

declare(strict_types=1);

// The labels of the types Kami declares itself; a site's own kami-types.php wins, key by key, in any language it has.
return [
    'site_settings' => [
        'label' => 'Réglages du site',
        'fields' => [
            'site_name' => ['label' => 'Nom du site'],
            'default_description' => ['label' => 'Description par défaut', 'help' => 'Décrit la page d’accueil aux moteurs de recherche quand elle n’a pas de description propre.'],
            'default_social_image' => ['label' => 'Image sociale par défaut', 'help' => 'Montrée quand une page partagée sur un réseau social ou une messagerie n’a pas d’image propre. Toute image est recadrée en 1200 × 630.'],
        ],
    ],
];
