<?php

declare(strict_types=1);

return [

    'fields' => [
        'title' => 'Title',
        'slug' => 'Slug',
        'slug_help' => 'The last part of the address. Left blank, it is made from the title.',
        'parent' => 'Parent',
        'parent_cycle' => 'An Entry cannot sit under itself or one of its children.',
        'untitled' => 'Untitled',
        'path_taken' => "Another Entry already answers at this type's Prefix: change the Prefix.",
        'slug_moves' => 'Changing it moves the address: the old one stops answering.',
        'published_at' => 'Publication date',
        'published_at_help' => 'In the :timezone time zone. Left blank, publishing sets it to now; a date to come schedules the publication.',
        'shared' => 'Shared by every language: changing it here changes it everywhere.',
    ],

    'locale' => 'Translation: :LOCALE',

    'locale_state' => ':LOCALE · :state',

    'state' => [
        'label' => 'State',
        'label_in' => 'State (:LOCALE)',
        'draft' => 'Draft',
        'scheduled' => 'Scheduled',
        'published' => 'Published',
        'missing' => 'Missing',
        'no_translation' => 'No translation yet',
    ],

    'actions' => [
        'children' => 'Children',
        'create_target' => 'Create a :type',
        'save_draft' => 'Save draft',
        'publish' => 'Publish',
        'unpublish' => 'Unpublish',
        'view' => 'View on the site',
    ],

    'rich_text' => [
        'unavailable_block' => 'Block unavailable for now: :handle',
    ],
];
