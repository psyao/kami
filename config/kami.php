<?php

declare(strict_types=1);

return [

    /*
    | The site's Content Types: `final` classes extending `Psyao\Kami\Content\ContentType` (ADR 0007).
    */
    'types' => [],

    /*
    | The Sites this installation serves, by handle. Each has its URL, its Locales (the first is the default)
    | and the time zone its dates are displayed in; dates are stored in UTC (ADR 0004).
    */
    /*
    | The Roles a User may hold, by handle, each with its Permissions; `*` matches any part (`kami.*`, `kami.entries.*.view`).
    | Checks always target a Permission, never a Role (ADR 0014). Labels come from the `kami-roles` translation file.
    */
    'roles' => [
        'administrator' => ['kami.*'],
    ],

    'sites' => [
        'default' => [
            'url' => env('APP_URL', 'http://localhost'),
            'locales' => [env('APP_LOCALE', 'en')],
            'timezone' => 'UTC',
        ],
    ],

];
