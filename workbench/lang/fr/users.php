<?php

declare(strict_types=1);

// L'écran Utilisateurs de l'hôte.
return [
    'label' => 'Utilisateur',
    'plural_label' => 'Utilisateurs',
    'name' => 'Nom',
    'email' => 'E-mail',
    'link' => [
        'title' => 'Envoyez ce lien à :name pour qu’il ou elle choisisse son mot de passe',
        'body' => ':url — il sert une fois et expire dans :minutes minutes. Passé ce délai, un nouveau lien se demande depuis la page de connexion.',
        'copy' => 'Copier le lien',
        'copied' => 'Copié',
    ],
];
