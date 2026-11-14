<?php

declare(strict_types=1);

// The Workbench Site's Block labels, as a site's lang/fr/kami-blocks.php gives them.
return [
    'callout' => [
        'label' => 'Encadré',
        'fields' => [
            'text' => ['label' => 'Texte'],
            'tone' => ['label' => 'Ton', 'options' => ['info' => 'Information', 'warning' => 'Avertissement']],
            'image' => ['label' => 'Image'],
        ],
    ],
];
