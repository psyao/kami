<?php

declare(strict_types=1);

// Kami's own Blocks: a site's lang/<locale>/kami-blocks.php overrides any line by the same key.
return [
    'image' => [
        'label' => 'Image',
        'fields' => [
            'image' => ['label' => 'Image'],
            'caption' => ['label' => 'Caption'],
        ],
    ],
    'video' => [
        'label' => 'Video',
        'fields' => [
            'url' => ['label' => 'Address', 'help' => 'A YouTube or Vimeo address, such as https://www.youtube.com/watch?v=…. Nothing loads from the provider until a visitor clicks.'],
            'caption' => ['label' => 'Caption'],
        ],
        'invalid' => 'The :attribute is not a YouTube or Vimeo address.',
        'title' => ':provider video',
        'watch' => 'Watch the video on :provider',
        'watch_titled' => 'Watch “:title” on :provider',
    ],
];
