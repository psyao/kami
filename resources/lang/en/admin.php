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

    'changed_since' => [
        'named' => ':name changed this Entry on :time, after you opened it. Nothing was saved: reload the page to see the changes.',
        'unnamed' => 'This Entry was changed on :time, after you opened it. Nothing was saved: reload the page to see the changes.',
    ],

    'trash' => [
        'filter' => 'Trash',
        'without' => 'Outside the Trash',
        'with' => 'With the Trash',
        'only' => 'In the Trash',
        'heading' => 'Move :title to the Trash',
        'description' => 'It leaves the site in every language until someone restores it.',
        'used_by' => 'Used by :entries: they will read it as absent until someone restores it.',
        'done' => 'Moved to the Trash',
        'singleton' => 'A Singleton or a Global Set cannot go to the Trash: a Site never loses it.',
        'children' => 'Move or trash its children first: :children.',
    ],

    'restore' => [
        'parent' => 'Restore its Parent first: :parent.',
    ],

    'titles' => [
        'more' => ':titles and :count more',
    ],

    'state' => [
        'label' => 'State',
        'label_in' => 'State (:LOCALE)',
        'draft' => 'Draft',
        'scheduled' => 'Scheduled',
        'scheduled_at' => 'Scheduled for :date',
        'published' => 'Published',
        'missing' => 'Missing',
        'no_translation' => 'No translation yet',
    ],

    'actions' => [
        'children' => 'Children',
        'create_target' => 'Create a :type',
        'save_draft' => 'Save draft',
        'preview' => 'Preview',
        'publish' => 'Publish',
        'schedule' => 'Schedule',
        'unpublish' => 'Unpublish',
        'view' => 'View on the site',
        'trash' => 'Move to Trash',
    ],

    'preview' => [
        'label' => 'Preview',
        'banner' => 'Preview of unsaved changes: visitors do not see this page.',
    ],

    'rich_text' => [
        'unavailable_block' => 'Block unavailable for now: :handle',
    ],

    'reminders' => [
        'title' => 'Accessibility reminders for :field',
        'skipped_level' => 'A heading goes from H:from to H:to, skipping a level.',
        'empty_heading' => 'A heading is empty.',
        'empty_link' => 'A link has no text.',
    ],
];
