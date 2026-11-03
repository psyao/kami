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

    'locale_state' => ':LOCALE · :state',

    'changed_since' => [
        'named' => ':name a modifié cette Entry le :time, après que vous l’avez ouverte. Rien n’a été enregistré : rechargez la page pour voir les modifications.',
        'unnamed' => 'Cette Entry a été modifiée le :time, après que vous l’avez ouverte. Rien n’a été enregistré : rechargez la page pour voir les modifications.',
    ],

    'trash' => [
        'filter' => 'Corbeille',
        'without' => 'Hors de la corbeille',
        'with' => 'Avec la corbeille',
        'only' => 'Dans la corbeille',
        'heading' => 'Mettre :title à la corbeille',
        'description' => 'Elle quitte le site dans toutes les langues jusqu’à ce que quelqu’un la restaure.',
        'used_by' => 'Utilisée par :entries : elles la liront comme absente jusqu’à ce que quelqu’un la restaure.',
        'done' => 'Mise à la corbeille',
        'singleton' => 'Un Singleton ou un Global Set ne va pas à la corbeille : un Site ne le perd jamais.',
        'children' => 'Déplacez ou mettez d’abord ses enfants à la corbeille : :children.',
    ],

    'restore' => [
        'parent' => 'Restaurez d’abord son Parent : :parent.',
    ],

    'titles' => [
        'more' => ':titles et :count autres',
    ],

    'state' => [
        'label' => 'État',
        'label_in' => 'État (:LOCALE)',
        'draft' => 'Brouillon',
        'scheduled' => 'Programmé',
        'scheduled_at' => 'Programmé pour le :date',
        'published' => 'Publié',
        'missing' => 'Absente',
        'no_translation' => 'Pas encore traduit',
    ],

    'actions' => [
        'children' => 'Enfants',
        'create_target' => 'Créer : :type',
        'save_draft' => 'Enregistrer le brouillon',
        'preview' => 'Prévisualiser',
        'publish' => 'Publier',
        'schedule' => 'Programmer',
        'unpublish' => 'Dépublier',
        'view' => 'Voir sur le site',
        'trash' => 'Mettre à la corbeille',
    ],

    'preview' => [
        'label' => 'Prévisualisation',
        'banner' => 'Prévisualisation de modifications non enregistrées : les visiteurs ne voient pas cette page.',
    ],

    'rich_text' => [
        'unavailable_block' => 'Bloc indisponible pour le moment : :handle',
    ],

    'reminders' => [
        'title' => 'Rappels d’accessibilité pour :field',
        'skipped_level' => 'Un titre passe de H:from à H:to et saute un niveau.',
        'empty_heading' => 'Un titre est vide.',
        'empty_link' => 'Un lien n’a pas de texte.',
    ],
];
