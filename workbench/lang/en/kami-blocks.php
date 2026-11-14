<?php

declare(strict_types=1);

// The Workbench Site's Block labels, as a site's lang/en/kami-blocks.php gives them.
return [
    'callout' => [
        'label' => 'Callout',
        'fields' => [
            'text' => ['label' => 'Text'],
            'tone' => ['label' => 'Tone', 'options' => ['info' => 'Information', 'warning' => 'Warning']],
            'image' => ['label' => 'Image'],
        ],
    ],
];
