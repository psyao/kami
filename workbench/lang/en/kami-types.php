<?php

declare(strict_types=1);

// The Workbench Site's labels, as a site's lang/en/kami-types.php gives them.
return [
    'article' => [
        'label' => 'Article',
        'plural' => 'Articles',
        'fields' => [
            'summary' => ['label' => 'Summary', 'help' => 'Two sentences at most.'],
            'author' => ['label' => 'Author'],
            'featured' => ['label' => 'Featured'],
            'accent' => ['label' => 'Accent'],
            'website' => ['label' => 'Website'],
            'contact' => ['label' => 'Contact'],
            'pages' => ['label' => 'Pages'],
            'rating' => ['label' => 'Rating'],
            'starts_on' => ['label' => 'Starts on'],
            'starts_at' => ['label' => 'Starts at'],
            'kind' => ['label' => 'Kind', 'options' => ['news' => 'News', 'review' => 'Review']],
        ],
    ],
    'page' => [
        'label' => 'Page',
        'plural' => 'Pages',
        'fields' => [
            'body' => ['label' => 'Body'],
        ],
    ],
    'label' => [
        'label' => 'Label',
        'plural' => 'Labels',
        'fields' => [
            'colour' => ['label' => 'Colour'],
        ],
    ],
    'project' => [
        'label' => 'Project',
        'plural' => 'Projects',
        'tabs' => [
            'details' => ['label' => 'Details'],
            'story' => ['label' => 'Story'],
        ],
        'sections' => [
            'highlight' => ['label' => 'Highlight'],
        ],
        'fields' => [
            'client' => ['label' => 'Client'],
            'featured' => ['label' => 'Featured'],
            'featured_note' => ['label' => 'Why it is featured'],
            'kind' => ['label' => 'Kind', 'options' => ['news' => 'News', 'review' => 'Review', 'essay' => 'Essay']],
            'source' => ['label' => 'Source'],
            'story' => ['label' => 'Story'],
            'launched_on' => ['label' => 'Launched on'],
        ],
    ],
];
