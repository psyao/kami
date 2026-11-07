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
        'encoding' => 'Le champ :attribute doit être un texte UTF-8 valide.',
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

    'bulk' => [
        'partial' => ':count sur :total traités',
        'reason' => ':reason (:count)',
        'trash_heading' => 'Mettre les entrées sélectionnées à la corbeille',
        'trash_description' => 'Elles quittent le site dans toutes les langues jusqu’à ce que quelqu’un les restaure.',
        'trash_done' => 'Mises à la corbeille',
        'delete_description' => 'Elles sont supprimées définitivement, dans toutes les langues. Leurs adresses indiquent que les pages n’existent plus (410).',
        'publish_heading' => 'Publier les entrées sélectionnées',
        'unpublish_heading' => 'Dépublier les entrées sélectionnées',
        'publish_done' => 'Publiées',
        'unpublish_done' => 'Dépubliées',
        'forbidden' => 'Non autorisé pour vous',
        'trashed' => 'Dans la corbeille',
        'not_trashed' => 'Hors de la corbeille',
        'no_translation' => 'Pas de traduction :locale',
        'already_published' => 'Déjà publiée ou programmée',
        'already_draft' => 'Déjà en brouillon',
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
        'trashed' => ':title (dans la corbeille)',
    ],

    'password_reset' => [
        'sent' => 'Consultez vos e-mails',
        'sent_body' => 'Si un compte utilise cette adresse, nous lui avons envoyé un lien pour réinitialiser son mot de passe.',
    ],

    'users' => [
        'created_by' => 'Créé par :name',
        'last_modified_by' => 'Modifié en dernier par :name',
        'creator' => 'Créé par',
        'last_modifier' => 'Modifié en dernier par',
        'nobody' => 'Personne',
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

    'widgets' => [
        'type' => 'Type',
        'recently_edited' => [
            'heading' => 'Modifiés récemment',
            'updated_at' => 'Modifié',
            'empty' => 'Rien de modifié pour l’instant',
        ],
        'scheduled' => [
            'heading' => 'Programmés',
            'empty' => 'Rien de programmé',
        ],
        'attention' => [
            'heading' => 'À surveiller',
            'checked_at' => 'Dernier contrôle le :date.',
            'never' => 'Aucun contrôle pour l’instant',
            'none' => 'Aucun problème trouvé',
            'run' => 'Lancez :command sur le serveur pour contrôler à nouveau cette installation.',
            'severity' => 'Gravité',
            'problem' => 'Problème',
            'fix' => 'Correction : :fix',
            'error' => 'Erreur',
            'warning' => 'Avertissement',
            'info' => 'Information',
        ],
    ],

    'search' => [
        'locale' => 'Langue',
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
        'alt_title' => 'Images sans texte alternatif',
        'alt_body' => 'Les lecteurs d’écran n’en disent rien : décrivez-les sous Médias, ou marquez-les comme décoratives.',
    ],

    'media' => [
        'label' => 'média',
        'plural_label' => 'Médias',
        'columns' => [
            'thumbnail' => 'Aperçu',
            'name' => 'Nom',
            'family' => 'Type',
            'size' => 'Taille',
            'alt' => 'Texte alternatif',
        ],
        'families' => [
            'image' => 'Image',
            'document' => 'Document',
        ],
        'replace' => [
            'label' => 'Remplacer le fichier',
            'description' => 'Chaque page qui utilise cet élément le garde et affiche le nouveau fichier.',
            'file' => 'Nouveau fichier',
            'submit' => 'Remplacer',
            'done' => 'Fichier remplacé',
        ],
        'used_by' => [
            'label' => 'Utilisé par',
            'none' => 'Aucune Entry ne l’utilise.',
            'more' => 'et :count autres',
        ],
        'delete' => [
            'description' => 'Il est supprimé définitivement, avec son fichier, dans toutes les langues. Les médias n’ont pas de corbeille.',
            'used' => '{1} Une Entry utilise cet élément : :entries. Retirez-le d’abord de celle-ci, ou supprimez-la définitivement si elle est à la corbeille.|[2,*] Des Entries utilisent cet élément : :entries. Retirez-le d’abord de celles-ci, ou supprimez définitivement celles qui sont à la corbeille.',
        ],
        'fields' => [
            'preview' => 'Aperçu',
            'alt' => 'Texte alternatif',
            'alt_help' => 'Ce que montre l’image, pour qui ne peut pas la voir. Obligatoire dans la langue par défaut, sauf si l’image est décorative.',
            'decorative' => 'Décorative',
            'decorative_help' => 'L’image n’apporte rien à la page : les lecteurs d’écran l’ignorent.',
            'caption' => 'Légende',
            'credit' => 'Crédit',
        ],
        'filters' => [
            'alt_missing' => 'Texte alternatif manquant',
        ],
        'alt' => [
            'described' => 'Rédigé',
            'missing' => 'Manquant',
            'decorative' => 'Décoratif',
            'not_needed' => 'Non requis',
        ],
        'upload' => [
            'label' => 'Ajouter des fichiers',
            'files' => 'Fichiers',
            'limits' => 'Jusqu’à :count fichiers, :size chacun.',
            'submit' => 'Ajouter',
            'saved' => '{1} :count fichier ajouté.|[2,*] :count fichiers ajoutés.',
            'refused' => '{1} :count fichier refusé|[2,*] :count fichiers refusés',
            'failed' => 'Ce fichier n’a pas pu être enregistré : réessayez, ou prévenez l’administrateur du site.',
        ],
        'picker' => [
            'upload' => 'Ajouter un fichier : :field',
            'file' => 'Fichier',
            'limit' => 'Jusqu’à :size.',
        ],
        'refused' => [
            'type' => 'Les fichiers de type :type ne sont pas acceptés.',
            'unsupported' => 'Les fichiers de type :type ne sont pas encore pris en charge.',
            'undecodable' => 'Cette image est illisible : le fichier est endommagé ou n’est pas une image.',
            'pixels' => 'Cette image dépasse :megapixels mégapixels : réduisez-la avant de l’envoyer.',
            'family' => [
                'image' => 'Seule une autre image peut remplacer celle-ci.',
                'document' => 'Seul un autre document peut remplacer celui-ci.',
            ],
        ],
    ],
];
