<?php

declare(strict_types=1);

namespace Psyao\Kami\Seo\Internal;

use Closure;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\SiteSettings;
use Psyao\Kami\Media\Internal\Conversion;
use Psyao\Kami\Media\Internal\ImagePresets;
use Psyao\Kami\Media\Internal\Picture;
use Psyao\Kami\Media\Media;
use Psyao\Kami\Routing\Internal\Path;
use Psyao\Kami\Seo\PageMeta;
use Psyao\Kami\Sites\Internal\Site;

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
        $image = self::image($settings, fn (): mixed => $entry->getAttribute('seo_image'), fn (): ?Media => $type->socialImage($entry));
        $article = $type->ogType() === 'article';
        $site = Site::current();
        $locale = $entry->readLocale();
        $alternates = $preview || $entry->noindex === true ? [] : self::alternates($site, self::indexableUrls($site, $entry));

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
            image: $image?->src,
            imageWidth: $image?->width,
            imageHeight: $image?->height,
            imageAlt: self::filled($image?->alt),
            ogType: $type->ogType(),
            publishedTime: $article ? $entry->published_at : null,
            // A Preview of an Entry never saved has no save to date.
            modifiedTime: $article && $entry->hasAttribute('updated_at') ? $entry->updated_at?->toImmutable() : null,
            alternates: $alternates,
            ogLocale: $site->ogLocale($locale),
            ogLocaleAlternates: array_values(array_filter(array_map($site->ogLocale(...), array_keys(array_diff_key($alternates, [$locale => true, 'x-default' => true]))))),
        );
    }

    /** A page Kami does not render, a site route's or an error page's: the `title` and `description` its view gives. */
    public static function forSite(?string $title = null, ?string $description = null): PageMeta
    {
        $settings = SiteSettings::entry();
        $siteName = self::siteName($settings);
        $image = self::image($settings);
        $site = Site::current();

        return new PageMeta(
            title: self::title($title, $siteName),
            description: self::filled($description),
            canonical: null,
            robots: self::robots(false),
            siteName: $siteName,
            image: $image?->src,
            imageWidth: $image?->width,
            imageHeight: $image?->height,
            imageAlt: self::filled($image?->alt),
            ogLocale: $site->ogLocale($site->currentLocale()),
        );
    }

    /**
     * The Entry's URL in each Locale it is visible in and not Hidden from search engines: what the sitemap lists and the language links name.
     *
     * @return array<string, string>
     */
    public static function indexableUrls(Site $site, Entry $entry): array
    {
        return array_filter(array_combine($site->locales, array_map(fn (string $locale): ?string => $entry->translation($locale)?->noindex === true ? null : $entry->url($locale), $site->locales)));
    }

    /**
     * The language links between those URLs, with `x-default` to the default Locale's; none below two.
     *
     * @param  array<string, string>  $urls  From `indexableUrls()`.
     * @return array<string, string>
     */
    public static function alternates(Site $site, array $urls): array
    {
        if (count($urls) < 2) {
            return [];
        }

        return isset($urls[$site->defaultLocale()]) ? [...$urls, 'x-default' => $urls[$site->defaultLocale()]] : $urls;
    }

    /**
     * The first image of the chain that has a Conversion, the Site's default last: an SVG, which crawlers do not read, falls to the next.
     *
     * @param  Closure(): mixed  ...$links  Read in turn, only as far as needed.
     */
    private static function image(?Entry $settings, Closure ...$links): ?Picture
    {
        foreach ([...$links, fn (): mixed => $settings?->getAttribute('default_social_image')] as $link) {
            $media = $link();

            if ($media instanceof Media && Conversion::isConvertible($media)) {
                return Picture::of($media, ImagePresets::SOCIAL);
            }
        }

        return null;
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
