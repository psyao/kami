<?php

declare(strict_types=1);

namespace Psyao\Kami\Seo\Internal;

use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\SiteSettings;
use Psyao\Kami\Routing\Internal\Path;
use Psyao\Kami\Seo\PageMeta;

/**
 * Derives a page's `PageMeta` from what is already loaded, with no cross-Locale fallback but the Site name's (#23 decision 2).
 *
 * @internal
 */
final class ResolvePageMeta
{
    /** The request attribute holding the page's `PageMeta`, set before its type renders it. */
    public const string ATTRIBUTE = 'kami.seo';

    /** @param  bool  $preview  A Preview: always `noindex`, and the saved URL as canonical, none before the first save. */
    public static function forEntry(ContentType $type, Entry $entry, bool $preview = false): PageMeta
    {
        $settings = SiteSettings::entry();
        $siteName = self::siteName($settings);
        $home = Path::of($type, $entry->slug) === '';

        return new PageMeta(
            title: self::filled($entry->meta_title) ?? self::title($home ? null : $entry->title, $siteName),
            description: self::filled($entry->meta_description) ?? self::filled($type->summary($entry)) ?? ($home ? self::ownValue($settings, 'default_description') : null),
            canonical: match (true) {
                ! $preview => $entry->url(),
                // A Preview of an Entry never saved has no id.
                $entry->getRawOriginal('id') === null => null,
                default => Entry::query()->whereKey($entry->getRawOriginal('id'))->first()?->url($entry->readLocale()),
            },
            robots: self::robots($preview || $entry->noindex === true),
            siteName: $siteName,
        );
    }

    /** A page Kami does not render, a site route's or an error page's: the `title` and `description` its view gives. */
    public static function forSite(?string $title = null, ?string $description = null): PageMeta
    {
        $siteName = self::siteName(SiteSettings::entry());

        return new PageMeta(
            title: self::title($title, $siteName),
            description: self::filled($description),
            canonical: null,
            robots: self::robots(false),
            siteName: $siteName,
        );
    }

    /** `kami::seo.title` with the page's title, or the Site name alone without one. */
    private static function title(?string $title, string $siteName): string
    {
        $title = self::filled($title);

        return $title === null ? $siteName : (string) __('kami::seo.title', ['title' => $title, 'site' => $siteName]);
    }

    /** Blank in the page's Locale reads as the default Locale's, then as the application's name. */
    private static function siteName(?Entry $settings): string
    {
        return self::filled($settings?->getAttribute('site_name')) ?? config()->string('app.name');
    }

    /** A Translatable value of the page's Locale only, never one fallen back from the default Locale. */
    private static function ownValue(?Entry $settings, string $field): ?string
    {
        return ! $settings instanceof Entry || $settings->fallbackLocale($field) !== null ? null : self::filled($settings->getAttribute($field));
    }

    private static function robots(bool $hidden): ?string
    {
        return match (true) {
            ! config()->boolean('kami.indexable') => 'noindex, nofollow',
            $hidden => 'noindex',
            default => null,
        };
    }

    private static function filled(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
