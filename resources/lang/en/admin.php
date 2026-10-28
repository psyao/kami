<?php

declare(strict_types=1);

return [

    'fields' => [
        'title' => 'Title',
        'slug' => 'Slug',
        'slug_help' => 'The last part of the address. Left blank, it is made from the title.',
        'published_at' => 'Publication date',
        'published_at_help' => 'In the :timezone time zone. Left blank, publishing sets it to now; a date to come schedules the publication.',
    ],

    'state' => [
        'label' => 'State',
        'draft' => 'Draft',
        'scheduled' => 'Scheduled',
        'published' => 'Published',
    ],

    'actions' => [
        'save_draft' => 'Save draft',
        'publish' => 'Publish',
        'unpublish' => 'Unpublish',
    ],

];
