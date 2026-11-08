<?php

declare(strict_types=1);

// The labels of the types Kami declares itself; a site's own kami-types.php wins, key by key, in any language it has.
return [
    'site_settings' => [
        'label' => 'Site settings',
        'fields' => [
            'site_name' => ['label' => 'Site name'],
            'default_description' => ['label' => 'Default description', 'help' => 'Describes the home page to search engines when it has no description of its own.'],
            'default_social_image' => ['label' => 'Default social image', 'help' => 'Shown when a page shared on a social network or in a messenger has no image of its own. Any image is cut to 1200 × 630.'],
        ],
    ],
];
