<?php

declare(strict_types=1);

return [

    'fields' => [
        'title' => 'Titre',
        'slug' => 'Slug',
        'slug_help' => 'La dernière partie de l’adresse. Laissé vide, il est tiré du titre.',
        'published_at' => 'Date de publication',
        'published_at_help' => 'Dans le fuseau horaire :timezone. Laissée vide, la publication la fixe à maintenant ; une date à venir programme la publication.',
        'shared' => 'Commun à toutes les langues : le modifier ici le modifie partout.',
    ],

    'locale' => 'Traduction : :locale',

    'state' => [
        'label' => 'État',
        'draft' => 'Brouillon',
        'scheduled' => 'Programmé',
        'published' => 'Publié',
        'missing' => 'Absente',
    ],

    'actions' => [
        'save_draft' => 'Enregistrer le brouillon',
        'publish' => 'Publier',
        'unpublish' => 'Dépublier',
        'edit_locale' => 'Modifier :locale',
        'add_locale' => 'Ajouter :locale',
        'view' => 'Voir sur le site',
    ],

];
