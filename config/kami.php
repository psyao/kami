<?php

declare(strict_types=1);

use Psyao\Kami\Admin\Internal\EntryResource;
use Psyao\Kami\Admin\Internal\MediaResource;

return [

    /*
    | The site's Content Types: `final` classes extending `Psyao\Kami\Content\ContentType` (ADR 0007).
    */
    'types' => [],

    /*
    | The site's Forms: classes extending `Psyao\Kami\Forms\Form`, whose inputs the site writes inside `<x-kami::form>` (ADR 0015).
    | Labels come from the `kami-forms` translation file, field labels from `validation.attributes` (docs/guide/forms.md).
    */
    'forms' => [],

    /*
    | The Roles a User may hold, by handle, each with its Permissions; `*` matches any part (`kami.*`, `kami.entries.*.view`).
    | Checks always target a Permission, never a Role (ADR 0014). Labels come from the `kami-roles` translation file.
    */
    'roles' => [
        'administrator' => ['kami.*'],
    ],

    /*
    | The Filament Resources that edit Entries, registered once per type, and the Media library. Only Kami's own are supported in the MVP:
    | this key is the door for overridable core Resources, deferred to V1 (roadmap, feature 82; ADR 0029).
    */
    'resources' => [
        'entry' => EntryResource::class,
        'media' => MediaResource::class,
    ],

    /*
    | Whether search engines are welcome (ADR 0027): on in production, off elsewhere. Off, every front response carries
    | `X-Robots-Tag: noindex, nofollow` and `robots.txt` disallows everything (docs/guide/seo.md). The admin is never indexed.
    */
    'indexable' => (bool) env('KAMI_INDEXABLE', app()->isProduction()),

    /*
    | The Sites this installation serves, by handle. Each has its URL, its Locales (the first is the default)
    | and the time zone its dates are displayed in; dates are stored in UTC (ADR 0004). A Locale is a code, or a code
    | with its options: `'fr' => ['region' => 'CH']` gives `og:locale` `fr_CH`, as `fr-ch` does (docs/guide/seo.md#languages).
    */
    'sites' => [
        'default' => [
            'url' => env('APP_URL', 'http://localhost'),
            'locales' => [env('APP_LOCALE', 'en')],
            'timezone' => 'UTC',
        ],
    ],

    /*
    | The Media library (ADR 0017). `originals_disk`: any Laravel disk, local or remote; keep it private, as originals keep
    | their metadata: a local disk under `public/` is refused (docs/guide/hosting.md#conversions). `mimes`: the types
    | accepted per family, read from the file's content; video and audio stay closed.
    | `max_pixels`: an image above it is refused at upload, capping the cost of generating its Conversions.
    | `public_disk`: a local disk the Conversions are written to, under `media/`. `presets`: the site's `Psyao\Kami\Media\ImagePreset`s,
    | next to Kami's own `admin-thumb`, `original` and `kami-social`. `avif`: off, Conversions are offered in WebP and the
    | fallback format only, as AVIF costs the most to encode (docs/guide/media.md#formats).
    */
    'media' => [
        'presets' => [],
        'originals_disk' => 'local',
        'public_disk' => 'public',
        'mimes' => [
            'image' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml'],
            'document' => ['application/pdf'],
        ],
        'max_pixels' => 40_000_000,
        'avif' => true,
    ],

    /*
    | The Audit log (ADR 0022). `retention`: the months an event is kept before `kami:audit:prune` deletes it, 1 or more
    | (docs/guide/audit-log.md#retention).
    */
    'audit' => [
        'retention' => 12,
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

    /*
    | The page cache (ADR 0021): a page Kami rendered once is served again until anything visitors might see changes.
    | `enabled`: on unless `APP_DEBUG`, so a page being debugged is never a cached one (docs/guide/page-cache.md).
    */
    'page_cache' => [
        'enabled' => ! env('APP_DEBUG', false),
    ],

    /*
    | The Tick (ADR 0020). `token`: the secret in the URL a host's planner calls, `/kami/tick/{token}`; the URL answers 404
    | while it is empty. `php artisan kami:tick:url` generates one (docs/guide/scheduler.md).
    */
    'tick' => [
        'token' => env('KAMI_TICK_TOKEN', ''),
    ],

];
