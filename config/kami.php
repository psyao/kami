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

    /*
    | The front's Content Security Policy and headers (ADR 0018); the admin profile is fixed. Every option that weakens
    | them is off by default and raises a `kami:doctor` warning when on (docs/guide/security.md#weakening-options).
    |
    | `csp`: sources added per directive, merged with Kami's and never replacing them (`'img-src' => ['https://cdn.example.com']`).
    | `headers`: a companion header replaced by value, or omitted when set to `null`.
    | `csp_report_uri`: where browsers send violation reports, with the page URL and the visitor's address.
    | `csp_report_only`: reports violations without blocking them.
    */
    'security' => [
        'csp' => [],
        'headers' => [],
        'csp_report_uri' => null,
        'csp_report_only' => false,
    ],

];
