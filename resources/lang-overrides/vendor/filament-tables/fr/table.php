<?php

declare(strict_types=1);

// The lines Filament's own French table strings lack, merged over them (#589): a line Filament ships is removed here.
return [

    'column_manager' => [

        'position' => 'Position :position sur :count.',

        'position_in_group' => 'Position :position sur :count dans :group.',

        'reordered' => ':column, maintenant en position :position sur :count.',

        'reordered_deferred' => ':column, maintenant en position :position sur :count. Appliquez les colonnes pour enregistrer vos modifications.',

        'reordered_in_group' => ':column, maintenant en position :position sur :count dans :group.',

        'reordered_in_group_deferred' => ':column, maintenant en position :position sur :count dans :group. Appliquez les colonnes pour enregistrer vos modifications.',

        'actions' => [

            'move_up' => [
                'label' => 'Déplacer :column vers le haut',
            ],

            'move_down' => [
                'label' => 'Déplacer :column vers le bas',
            ],

            'reorder' => [
                'label' => 'Classer la colonne',
            ],

        ],

    ],

    'columns' => [

        'editable' => [
            'saving' => 'Enregistrement de la modification…',
            'saved' => 'Modification enregistrée.',
            'failure' => 'L’enregistrement n’a pas pu être confirmé. Actualisez la page avant de réessayer.',
            'unavailable' => 'Cet élément ne peut plus être modifié.',
        ],

        'icon' => [

            'boolean' => [
                'true' => 'Oui',
                'false' => 'Non',
            ],

        ],

        'select' => [

            'loading_message' => 'Chargement…',

            'no_options_message' => 'Aucune option disponible.',

            'no_search_results_message' => 'Aucune option ne correspond à votre recherche.',

            'placeholder' => 'Choisissez une option',

            'searching_message' => 'Recherche…',

            'search_prompt' => 'Commencez à taper pour rechercher',

        ],

    ],

    'actions' => [

        'reorder_record' => [
            'label' => 'Classer l’élément :key',
        ],

        'move_record_up' => [
            'label' => 'Déplacer :label vers le haut',
        ],

        'move_record_down' => [
            'label' => 'Déplacer :label vers le bas',
        ],

        'toggle_record_content' => [
            'label' => 'Déplier/replier l’élément :key',
        ],

    ],

    'loading' => 'Chargement…',

    'reordering' => [
        'position' => ':label, position :position sur :count.',
        'moved' => ':label, maintenant en position :position sur :count.',
    ],

    'result_count' => '{0} Aucun résultat|{1} :count résultat|[2,*] :count résultats',

];
