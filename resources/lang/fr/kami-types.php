<?php

declare(strict_types=1);

// The labels of the types Kami declares itself; a site's own kami-types.php wins, key by key, in any language it has.
return [
    'site_settings' => [
        'label' => 'Réglages du site',
        'fields' => [
            'site_name' => ['label' => 'Nom du site'],
            'default_description' => ['label' => 'Description par défaut', 'help' => 'Décrit la page d’accueil aux moteurs de recherche quand elle n’a pas de description propre.'],
        ],
    ],
];
