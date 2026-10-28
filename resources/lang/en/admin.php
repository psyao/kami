<?php

declare(strict_types=1);

return [

    'fields' => [
        'title' => 'Title',
        'slug' => 'Slug',
        'slug_help' => 'The last part of the address. Left blank, it is made from the title.',
        'published_at' => 'Publication date',
        'published_at_help' => 'In the :timezone time zone. Left blank, publishing sets it to now; a date to come schedules the publication.',
        'shared' => 'Shared by every language: changing it here changes it everywhere.',
    ],

    'locale' => 'Translation: :LOCALE',

    'state' => [
        'label' => 'State',
        'label_in' => 'State (:LOCALE)',
        'draft' => 'Draft',
        'scheduled' => 'Scheduled',
        'published' => 'Published',
        'missing' => 'Missing',
    ],

    'actions' => [
        'save_draft' => 'Save draft',
        'publish' => 'Publish',
        'unpublish' => 'Unpublish',
        'edit_locale' => 'Edit :LOCALE',
        'add_locale' => 'Add :LOCALE',
        'view' => 'View on the site',
    ],

];
