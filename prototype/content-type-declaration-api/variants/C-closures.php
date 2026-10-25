<?php

// PROTOTYPE, variant C, does not run. The same Project, declared like a migration
// Blueprint in a service provider, no class per type.

use Psyao\Kami\Content\TypeBlueprint;
use Psyao\Kami\Kami;

// app/Providers/ContentServiceProvider.php, boot()
Kami::type('project', function (TypeBlueprint $type) {
    $type->orderable();
    $type->model(\App\Models\Project::class);

    $type->text('client');
    $type->text('role');
    $type->textarea('summary')->inlineMarkdown();
    $type->url('url');
    $type->boolean('featured')->filterable();
    $type->entries('tags', of: 'tag')->multiple()->creatable();
});

Kami::type('home', function (TypeBlueprint $type) {
    $type->singleton()->prefix('');

    $type->tab('hero', function (TypeBlueprint $tab) {
        $tab->textarea('hero_title')->inlineMarkdown()->required();
        // ...
    });
});

// Where it hurts:
// - No class to reference: Entries::of('tag') takes strings, so a typo is a boot error,
//   not an IDE/Larastan error, and "find usages" of a type finds nothing.
// - Hooks a type overrides (render, seoImage, Archive query) need a second place to live.
// - Closures do not survive `php artisan config:cache`, so registration must run on every
//   boot (fine), but the declarations cannot be cached or introspected without booting.
// + Closest to Laravel's migration Blueprint; one file can hold a whole small site.
