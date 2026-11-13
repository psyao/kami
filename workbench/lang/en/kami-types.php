<?php

declare(strict_types=1);

// The Workbench Site's labels, as a site's lang/en/kami-types.php gives them.
return [
    'article' => [
        'label' => 'article',
        'plural' => 'Articles',
        'fields' => [
            'summary' => ['label' => 'Summary', 'help' => 'Two sentences at most.'],
            'body' => ['label' => 'Body'],
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
        'label' => 'page',
        'plural' => 'Pages',
        'fields' => [
            'body' => ['label' => 'Body'],
        ],
    ],
    'footer' => [
        'label' => 'Footer',
        'fields' => [
            'tagline' => ['label' => 'Tagline'],
            'contact' => ['label' => 'Contact'],
        ],
    ],
    'home' => [
        'label' => 'Home',
        'fields' => [
            'intro' => ['label' => 'Intro'],
        ],
    ],
    'testimonial' => [
        'label' => 'testimonial',
        'plural' => 'Testimonials',
        'fields' => [
            'quote' => ['label' => 'Quote'],
        ],
    ],
    'label' => [
        'label' => 'label',
        'plural' => 'Labels',
        'fields' => [
            'colour' => ['label' => 'Colour'],
        ],
    ],
    'tag' => [
        'label' => 'tag',
        'plural' => 'Tags',
    ],
    'project' => [
        'label' => 'project',
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
            'links' => ['label' => 'Links', 'fields' => [
                'label' => ['label' => 'Label'],
                'url' => ['label' => 'Address'],
            ]],
            'related' => ['label' => 'Related content'],
            'tags' => ['label' => 'Tags'],
            'cover' => ['label' => 'Cover image'],
            'gallery' => ['label' => 'Gallery'],
            'address' => ['label' => 'Address', 'fields' => [
                'street' => ['label' => 'Street'],
                'city' => ['label' => 'City'],
            ]],
        ],
    ],
];
