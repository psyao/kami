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
        $home = self::isHome($type, $entry);
        $media = self::image($settings, fn (): mixed => $entry->getAttribute('seo_image'), fn (): ?Media => $type->socialImage($entry));
        $image = $media instanceof Media ? Picture::of($media, ImagePresets::SOCIAL) : null;
        $article = $type->ogType() === 'article';
        $site = Site::current();
        $locale = $entry->readLocale();
        $alternates = $preview || $entry->noindex === true ? [] : self::alternates($site, self::indexableUrls($site, $entry));

        return new PageMeta(
            title: self::filled($entry->meta_title) ?? self::title($home ? null : $entry->title, $siteName),
            description: self::filled($entry->meta_description) ?? self::description($type, $entry, $settings, $home),
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

    /** An Archive's page in the current Locale, titled by its type's plural label, its address in every Locale as language links; one reached through a cursor is `paged()`. */
    public static function forArchive(ContentType $type, bool $paged = false): PageMeta
    {
        $site = Site::current();
        $urls = self::archiveUrls($site, $type);
        $alternates = self::alternates($site, $urls);
        $meta = self::forSite($type->pluralLabel());

        return self::with($meta, canonical: $paged ? null : $urls[$site->currentLocale()], robots: $paged ? self::pagedRobots($meta) : $meta->robots, alternates: $paged ? [] : $alternates);
    }

    /**
     * An Archive's first page in each Locale: what the sitemap lists and the language links name.
     *
     * @return array<string, string>
     */
    public static function archiveUrls(Site $site, ContentType $type): array
    {
        return array_combine($site->locales, array_map(fn (string $locale): string => $site->url($locale, Path::normalise($type->prefix())), $site->locales));
    }

    /** A listing's page past the first, reached through a cursor: its address changes as Entries are added, so it is followed, never indexed, and names no canonical or language links. */
    public static function paged(PageMeta $meta): PageMeta
    {
        return self::with($meta, canonical: null, robots: self::pagedRobots($meta), alternates: []);
    }

    /** A site that is not indexable keeps its `noindex, nofollow`. */
    private static function pagedRobots(PageMeta $meta): ?string
    {
        return config()->boolean('kami.indexable') ? 'noindex, follow' : $meta->robots;
    }

    /**
     * The same head at another address: its canonical URL, robots and language links, the `og:locale:alternate` that follow from them.
     *
     * @param  array<string, string>  $alternates
     */
    private static function with(PageMeta $meta, ?string $canonical, ?string $robots, array $alternates): PageMeta
    {
        $site = Site::current();

        return new PageMeta(
            title: $meta->title,
            description: $meta->description,
            canonical: $canonical,
            robots: $robots,
            siteName: $meta->siteName,
            image: $meta->image,
            imageWidth: $meta->imageWidth,
            imageHeight: $meta->imageHeight,
            imageAlt: $meta->imageAlt,
            ogType: $meta->ogType,
            publishedTime: $meta->publishedTime,
            modifiedTime: $meta->modifiedTime,
            alternates: $alternates,
            ogLocale: $meta->ogLocale,
            ogLocaleAlternates: array_values(array_filter(array_map($site->ogLocale(...), array_keys(array_diff_key($alternates, [$site->currentLocale() => true, 'x-default' => true]))))),
        );
    }

    /** A page Kami does not render, a site route's or an error page's: the `title` and `description` its view gives. */
    public static function forSite(?string $title = null, ?string $description = null): PageMeta
    {
        $settings = SiteSettings::entry();
        $siteName = self::siteName($settings);
        $media = self::image($settings);
        $image = $media instanceof Media ? Picture::of($media, ImagePresets::SOCIAL) : null;
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

    /** The title the head gives an Entry with no Meta title, from the title `$title` the form holds: the admin's placeholder. */
    public static function derivedTitle(ContentType $type, ?Entry $entry, ?string $title, ?Entry $settings): string
    {
        return self::title(self::isHome($type, $entry) ? null : $title, self::siteName($settings));
    }

    /** The description the head gives an Entry with no Meta description: the admin's placeholder. */
    public static function derivedDescription(ContentType $type, Entry $entry, ?Entry $settings): ?string
    {
        return self::description($type, $entry, $settings, self::isHome($type, $entry));
    }

    /** The Social image the head gives an Entry with none of its own, the Site's default alone without an Entry yet: the admin's placeholder. */
    public static function derivedImage(ContentType $type, ?Entry $entry, ?Entry $settings): ?Media
    {
        return self::image($settings, ...($entry instanceof Entry ? [fn (): ?Media => $type->socialImage($entry)] : []));
    }

    /** The Site settings read in that Locale, as a page in it reads them. */
    public static function settingsIn(string $locale): ?Entry
    {
        $settings = SiteSettings::entry();
        $settings?->readIn($locale);

        return $settings;
    }

    /**
     * The first image of the chain that has a Conversion, the Site's default last: an SVG, which crawlers do not read, falls to the next.
     *
     * @param  Closure(): mixed  ...$links  Read in turn, only as far as needed.
     */
    private static function image(?Entry $settings, Closure ...$links): ?Media
    {
        foreach ([...$links, fn (): mixed => $settings?->getAttribute('default_social_image')] as $link) {
            $media = $link();

            if ($media instanceof Media && Conversion::isConvertible($media)) {
                return $media;
            }
        }

        return null;
    }

    /** The type's summary, else the Site's default description on the home page only. */
    private static function description(ContentType $type, Entry $entry, ?Entry $settings, bool $home): ?string
    {
        return self::filled($type->summary($entry)) ?? ($home ? self::ownValue($settings, 'default_description') : null);
    }

    /** The Entry at the empty Path, which the Site name titles alone. */
    private static function isHome(ContentType $type, ?Entry $entry): bool
    {
        return Path::of($type, $entry?->slug) === '';
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
