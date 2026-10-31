<?php

declare(strict_types=1);

return [

    'fields' => [
        'title' => 'Titre',
        'slug' => 'Slug',
        'slug_help' => 'La dernière partie de l’adresse. Laissé vide, il est tiré du titre.',
        'parent' => 'Parent',
        'parent_cycle' => 'Une Entry ne peut pas se placer sous elle-même ni sous un de ses enfants.',
        'untitled' => 'Sans titre',
        'path_taken' => 'Une autre Entry répond déjà au Prefix de ce type : changez le Prefix.',
        'slug_moves' => 'Le modifier déplace l’adresse : l’ancienne ne répond plus.',
        'published_at' => 'Date de publication',
        'published_at_help' => 'Dans le fuseau horaire :timezone. Laissée vide, la publication la fixe à maintenant ; une date à venir programme la publication.',
        'shared' => 'Commun à toutes les langues : le modifier ici le modifie partout.',
    ],

    'locale' => 'Traduction : :LOCALE',

    'state' => [
        'label' => 'État',
        'label_in' => 'État (:LOCALE)',
        'draft' => 'Brouillon',
        'scheduled' => 'Programmé',
        'published' => 'Publié',
        'missing' => 'Absente',
    ],

    'actions' => [
        'children' => 'Enfants',
        'create_target' => 'Créer : :type',
        'save_draft' => 'Enregistrer le brouillon',
        'publish' => 'Publier',
        'unpublish' => 'Dépublier',
        'edit_locale' => 'Modifier :LOCALE',
        'add_locale' => 'Ajouter :LOCALE',
        'view' => 'Voir sur le site',
    ],

];
