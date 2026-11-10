<?php

declare(strict_types=1);

return [

    'fields' => [
        'title' => 'Title',
        'slug' => 'Slug',
        'slug_help' => 'The last part of the address. Left blank, it is made from the title.',
        'parent' => 'Parent',
        'parent_cycle' => 'An Entry cannot sit under itself or one of its children.',
        'restricted' => 'Restricted Entry',
        'untitled' => 'Untitled',
        'encoding' => 'The :attribute field must be valid UTF-8 text.',
        'path_taken' => "Another Entry already answers at this type's Prefix: change the Prefix.",
        'slug_moves' => 'Changing it moves the address: once the page has been published, the old one redirects to the new one.',
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
        'delete_heading' => 'Delete the selected entries for good',
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
        'heading' => 'Delete :title for good',
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
        'restricted' => '{1} :count restricted Entry|[2,*] :count restricted Entries',
        'and_restricted' => ':titles and :restricted',
        'trashed' => ':title (in the Trash)',
    ],

    'password_reset' => [
        'sent' => 'Check your email',
        'sent_body' => 'If an account uses this address, we have sent it a link to reset its password.',
    ],

    'users' => [
        'created_by' => 'Created by :name',
        'last_modified_by' => 'Last modified by :name',
        'creator' => 'Created by',
        'last_modifier' => 'Last modified by',
        'nobody' => 'Nobody',
    ],

    'roles' => [
        'label' => 'Roles',
        'undeclared' => 'No Role is declared as :roles in config/kami.php.',
        'lockout' => 'This is the last User who may manage Users: give that right to another User first.',
        'lockout_delete' => 'This is the last User who may manage Users, so they cannot be deleted: give that right to another User first.',
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
        'delete' => 'Delete for good',
        'delete_selected' => 'Delete selected for good',
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
        'alt_title' => 'Images without alt text',
        'alt_body' => 'Screen readers say nothing of them: describe them under Media, or mark them decorative.',
    ],

    'media' => [
        'label' => 'Media item',
        'plural_label' => 'Media',
        'columns' => [
            'thumbnail' => 'Preview',
            'name' => 'Name',
            'family' => 'Type',
            'size' => 'Size',
            'alt' => 'Alt text',
        ],
        'families' => [
            'image' => 'Image',
            'document' => 'Document',
        ],
        'replace' => [
            'label' => 'Replace file',
            'description' => 'Every page using this item keeps it and shows the new file.',
            'file' => 'New file',
            'submit' => 'Replace',
            'done' => 'File replaced',
        ],
        'used_by' => [
            'label' => 'Used by',
            'none' => 'No Entry uses it.',
            'more' => 'and :count more',
        ],
        'delete' => [
            'description' => 'It is deleted for good, with its file, in every language. There is no Media trash.',
            'used' => '{1} An Entry uses this item: :entries. Remove it from that Entry, or delete the Entry for good if it is in the Trash, first.|[2,*] Entries use this item: :entries. Remove it from them, or delete those in the Trash for good, first.',
        ],
        'fields' => [
            'preview' => 'Preview',
            'alt' => 'Alt text',
            'alt_help' => 'What the image shows, for people who cannot see it. Required in the default language, unless the image is decorative.',
            'decorative' => 'Decorative',
            'decorative_help' => 'The image adds nothing to the page: screen readers skip it.',
            'caption' => 'Caption',
            'credit' => 'Credit',
        ],
        'filters' => [
            'alt_missing' => 'Alt text missing',
        ],
        'alt' => [
            'described' => 'Described',
            'missing' => 'Missing',
            'decorative' => 'Decorative',
            'not_needed' => 'Not needed',
        ],
        'upload' => [
            'label' => 'Upload',
            'files' => 'Files',
            'limits' => 'Up to :count files, :size each.',
            'submit' => 'Upload',
            'saved' => '{1} :count file uploaded.|[2,*] :count files uploaded.',
            'refused' => '{1} :count file refused|[2,*] :count files refused',
            'failed' => "This file could not be saved: try again, or tell the site's administrator.",
        ],
        'picker' => [
            'upload' => 'Upload a file for :field',
            'file' => 'File',
            'limit' => 'Up to :size.',
        ],
        'refused' => [
            'type' => 'Files of type :type are not accepted.',
            'unsupported' => 'Files of type :type are not supported yet.',
            'undecodable' => 'This image cannot be read: the file is damaged or not an image.',
            'pixels' => 'This image is larger than :megapixels megapixels: reduce it before uploading.',
            'family' => [
                'image' => 'Only another image can replace this one.',
                'document' => 'Only another document can replace this one.',
            ],
        ],
    ],

    'submissions' => [
        'label' => 'Submission',
        'plural_label' => 'Submissions',
        'unread' => 'Unread',
        'message' => 'Message',
        'received' => 'Received :date, in :locale',
        'reply' => 'Reply',
        'columns' => [
            'created_at' => 'Received',
            'summary' => 'Summary',
            'locale' => 'Language',
            'delivery' => 'Delivery',
        ],
        'delivery' => [
            'delivered' => 'Delivered',
            'failed' => 'Failed',
            'retrying' => 'Retrying',
            'pending' => 'Pending',
            'none' => 'Nothing to send',
        ],
        'deliveries' => [
            'channel' => 'Channel',
            'state' => 'State',
            'attempts' => 'Attempts',
            'delivered_at' => 'Delivered at',
            'last_error' => 'Last error',
        ],
    ],

    'audit' => [
        'label' => 'Audit event',
        'plural_label' => 'Audit log',
        'columns' => [
            'occurred_at' => 'When',
            'actor' => 'Who',
            'event' => 'Event',
            'subject' => 'Subject',
            'site' => 'Site',
            'ip' => 'IP',
            'context' => 'Context',
        ],
        'actors' => [
            'cli' => 'Command line',
            'system' => 'System',
        ],
        'deleted_user' => 'Deleted User (:id)',
        'filters' => [
            'user' => 'User',
            'event' => 'Event',
            'from' => 'From',
            'until' => 'Until',
        ],
        'subjects' => [
            'entry' => 'Entry',
            'media' => 'Media',
            'user' => 'User',
            'preview-link' => 'Preview link',
        ],
        'events' => [
            'entries' => [
                'published' => 'Entry published',
                'unpublished' => 'Entry unpublished',
                'publication-date-changed' => 'Publication date changed',
                'trashed' => 'Entry moved to the Trash',
                'restored' => 'Entry restored',
                'force-deleted' => 'Entry deleted for good',
            ],
            'preview-links' => [
                'created' => 'Preview link created',
                'revoked' => 'Preview link revoked',
            ],
            'form-submissions' => [
                'deleted' => 'Messages deleted',
            ],
            'media' => [
                'deleted' => 'Media deleted',
            ],
            'content' => [
                'migrated' => 'Content migrated',
            ],
            'roles' => [
                'granted' => 'Role granted',
                'revoked' => 'Role revoked',
            ],
            'users' => [
                'lockout-refused' => 'Lockout refused',
                'created' => 'User created',
                'deleted' => 'User deleted',
            ],
            'auth' => [
                'signed-in' => 'Signed in',
                'sign-in-failed' => 'Sign-in failed',
                'locked-out' => 'Sign-in locked out',
                'password-reset' => 'Password reset',
            ],
            'mfa' => [
                'enabled' => 'MFA enabled',
                'disabled' => 'MFA disabled',
                'recovery-used' => 'Recovery code used',
                'challenge-failed' => 'MFA code refused',
                'reset' => 'MFA reset',
            ],
        ],
    ],
];
