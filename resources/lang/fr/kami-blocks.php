<?php

declare(strict_types=1);

// Kami's own Blocks: a site's lang/<locale>/kami-blocks.php overrides any line by the same key.
return [
    'image' => [
        'label' => 'Image',
        'fields' => [
            'image' => ['label' => 'Image'],
            'caption' => ['label' => 'Légende'],
        ],
    ],
    'video' => [
        'label' => 'Vidéo',
        'fields' => [
            'url' => ['label' => 'Adresse', 'help' => 'Une adresse YouTube ou Vimeo, comme https://www.youtube.com/watch?v=…. Rien n’est chargé chez le fournisseur avant le clic du visiteur.'],
            'caption' => ['label' => 'Légende'],
        ],
        'invalid' => 'Le champ :attribute n’est pas une adresse YouTube ou Vimeo.',
        'title' => 'Vidéo :provider',
        'watch' => 'Regarder la vidéo sur :provider',
        'watch_titled' => 'Regarder « :title » sur :provider',
    ],
];
