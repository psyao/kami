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
        'encoding' => 'The :attribute field must be valid UTF-8 text.',
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

    'bulk' => [
        'partial' => ':count of :total done',
        'reason' => ':reason (:count)',
        'trash_heading' => 'Move the selected entries to the Trash',
        'trash_description' => 'They leave the site in every language until someone restores them.',
        'trash_done' => 'Moved to the Trash',
        'delete_description' => 'They are deleted for good, in every language. Their addresses say the pages are gone (410).',
        'publish_heading' => 'Publish the selected entries',
        'unpublish_heading' => 'Unpublish the selected entries',
        'publish_done' => 'Published',
        'unpublish_done' => 'Unpublished',
        'forbidden' => 'Not allowed for you',
        'trashed' => 'In the Trash',
        'not_trashed' => 'Not in the Trash',
        'no_translation' => 'No :locale translation',
        'already_published' => 'Already published or scheduled',
        'already_draft' => 'Already a Draft',
    ],

    'duplicate' => [
        'heading' => 'Duplicate :title',
        'description' => 'The copy is a Draft in every language, without children or Preview links.',
        'title' => ':title (copy)',
        'singleton' => 'A Singleton or a Global Set cannot be duplicated: a Site holds only one.',
        'done' => 'Copy created',
    ],

    'delete' => [
        'children' => 'Delete its children first: :children.',
        'description' => 'It is deleted for good, in every language. Its addresses send visitors to the replacement, or say the page is gone.',
        'replacement' => 'Replacement',
        'gone' => 'None: the page is gone (410)',
    ],

    'delete_translation' => [
        'published' => 'This translation was published once: unpublish it, or move the Entry to the Trash.',
        'last' => 'This is the only translation: move the Entry to the Trash instead.',
        'action' => 'Delete translation',
        'heading' => 'Delete the :LOCALE translation',
        'description' => 'Its values in this language and its address are deleted for good; the other languages stay.',
        'done' => 'Translation deleted',
    ],

    'titles' => [
        'more' => ':titles and :count more',
    ],

    'users' => [
        'created_by' => 'Created by :name',
        'last_modified_by' => 'Last modified by :name',
        'creator' => 'Created by',
        'last_modifier' => 'Last modified by',
        'nobody' => 'Nobody',
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

    'widgets' => [
        'type' => 'Type',
        'recently_edited' => [
            'heading' => 'Recently edited',
            'updated_at' => 'Edited',
            'empty' => 'Nothing edited yet',
        ],
        'scheduled' => [
            'heading' => 'Scheduled',
            'empty' => 'Nothing scheduled',
        ],
        'attention' => [
            'heading' => 'Needs attention',
            'checked_at' => 'Last checked on :date.',
            'never' => 'No check yet',
            'none' => 'No problem found',
            'run' => 'Run :command on the server to check this installation again.',
            'severity' => 'Severity',
            'problem' => 'Problem',
            'fix' => 'Fix: :fix',
            'error' => 'Error',
            'warning' => 'Warning',
            'info' => 'Information',
        ],
    ],

    'search' => [
        'locale' => 'Locale',
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
        'duplicate' => 'Duplicate',
    ],

    'preview' => [
        'label' => 'Preview',
        'banner' => 'Preview of unsaved changes: visitors do not see this page.',
    ],

    'preview_links' => [
        'action' => 'Preview links',
        'heading' => 'Preview links',
        'description' => 'Anyone holding a link reads this language as saved, Draft or Scheduled included, until it expires or is revoked.',
        'days' => 'Expires in',
        'days_suffix' => 'days',
        'create' => 'Create a link',
        'created' => 'Preview link created',
        'created_body' => 'Copy it now: it will not be shown again.',
        'url' => 'Link',
        'copied' => 'Copied',
        'close' => 'Close',
        'none' => 'No active link.',
        'active' => 'Expires on :date, created by :user',
        'unknown_user' => 'a deleted User',
        'revoke' => 'Revoke',
        'revoked' => 'Preview link revoked',
        'banner' => 'Preview of a saved version that visitors may not see. This link expires on :date.',
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

    'media' => [
        'refused' => [
            'type' => 'Files of type :type are not accepted.',
            'undecodable' => 'This image cannot be read: the file is damaged or not an image.',
            'pixels' => 'This image is larger than :megapixels megapixels: reduce it before uploading.',
        ],
    ],
];
