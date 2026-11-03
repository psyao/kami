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

    'duplicate' => [
        'heading' => 'Dupliquer :title',
        'description' => 'La copie est un brouillon dans toutes les langues, sans enfants ni liens de prévisualisation.',
        'title' => ':title (copie)',
        'singleton' => 'Un Singleton ou un Global Set ne se duplique pas : un Site n’en a qu’un.',
        'done' => 'Copie créée',
    ],

    'delete' => [
        'children' => 'Supprimez d’abord ses enfants : :children.',
        'description' => 'Elle est supprimée définitivement, dans toutes les langues. Ses adresses envoient les visiteurs vers la remplaçante, ou indiquent que la page n’existe plus.',
        'replacement' => 'Remplaçante',
        'gone' => 'Aucune : la page n’existe plus (410)',
    ],

    'delete_translation' => [
        'published' => 'Cette traduction a déjà été publiée : dépubliez-la, ou mettez l’Entry à la corbeille.',
        'last' => 'C’est la seule traduction : mettez plutôt l’Entry à la corbeille.',
        'action' => 'Supprimer la traduction',
        'heading' => 'Supprimer la traduction :LOCALE',
        'description' => 'Ses valeurs dans cette langue et son adresse sont supprimées définitivement ; les autres langues restent.',
        'done' => 'Traduction supprimée',
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
        'duplicate' => 'Dupliquer',
    ],

    'preview' => [
        'label' => 'Prévisualisation',
        'banner' => 'Prévisualisation de modifications non enregistrées : les visiteurs ne voient pas cette page.',
    ],

    'preview_links' => [
        'action' => 'Liens de prévisualisation',
        'heading' => 'Liens de prévisualisation',
        'description' => 'Toute personne qui détient un lien lit cette langue telle qu’enregistrée, brouillon ou programmée comprise, jusqu’à son expiration ou sa révocation.',
        'days' => 'Expire dans',
        'days_suffix' => 'jours',
        'create' => 'Créer un lien',
        'created' => 'Lien de prévisualisation créé',
        'created_body' => 'Copiez-le maintenant : il ne sera plus affiché.',
        'url' => 'Lien',
        'copied' => 'Copié',
        'close' => 'Fermer',
        'none' => 'Aucun lien actif.',
        'active' => 'Expire le :date, créé par :user',
        'unknown_user' => 'un utilisateur supprimé',
        'revoke' => 'Révoquer',
        'revoked' => 'Lien de prévisualisation révoqué',
        'banner' => 'Prévisualisation d’une version enregistrée que les visiteurs ne voient peut-être pas. Ce lien expire le :date.',
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
