<?php

declare(strict_types=1);

use Psyao\Kami\Admin\Internal\EntryResource;

return [

    /*
    | The site's Content Types: `final` classes extending `Psyao\Kami\Content\ContentType` (ADR 0007).
    */
    'types' => [],

    /*
    | The Roles a User may hold, by handle, each with its Permissions; `*` matches any part (`kami.*`, `kami.entries.*.view`).
    | Checks always target a Permission, never a Role (ADR 0014). Labels come from the `kami-roles` translation file.
    */
    'roles' => [
        'administrator' => ['kami.*'],
    ],

    /*
    | The Filament Resource that edits Entries, registered once per type. Only Kami's own is supported in the MVP:
    | this key is the door for overridable core Resources, deferred to V1 (roadmap, feature 82; ADR 0029).
    */
    'resources' => [
        'entry' => EntryResource::class,
    ],

    /*
    | The Sites this installation serves, by handle. Each has its URL, its Locales (the first is the default)
    | and the time zone its dates are displayed in; dates are stored in UTC (ADR 0004).
    */
    'sites' => [
        'default' => [
            'url' => env('APP_URL', 'http://localhost'),
            'locales' => [env('APP_LOCALE', 'en')],
            'timezone' => 'UTC',
        ],
    ],

];
