<?php

declare(strict_types=1);

// The host's Users screen.
return [
    'label' => 'User',
    'plural_label' => 'Users',
    'name' => 'Name',
    'email' => 'Email',
    'link' => [
        'title' => 'Send :name this link to set their password',
        'body' => ':url — it works once and expires in :minutes minutes. Past that, they can ask for a new one from the sign-in page.',
    ],
];
