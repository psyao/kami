<?php

declare(strict_types=1);

namespace Psyao\Kami\Routing\Internal;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Content\SiteSettings;
use Psyao\Kami\Seo\Internal\ResolvePageMeta;
use Psyao\Kami\Sites\Internal\Site;

/**
 * A Feed: the latest visible Entries of its types in the current Locale, as RSS 2.0 (Front rendering decision 7).
 * On a route of its own, so it never takes the `web` group nor a site's rendering middleware (ADR 0038, ADR 0040).
 *
 * @internal
 */
final class RenderFeed
{
    /** The items a Feed holds. */
    public const int ITEMS = 20;

    public function __invoke(Request $request): Response
    {
        $site = Site::current();
        $locale = $site->currentLocale();
        $path = Path::normalise($locale === $site->defaultLocale() ? $request->path() : substr($request->path(), strlen($locale) + 1));
        // A route table cached before the declarations changed.
        $types = resolve(ContentTypes::class)->feeds()[$path] ?? abort(404);
        $type = count($types) === 1 && $path !== 'feed' ? $types[0] : null;

        $query = Entry::query()->readIn($locale)->where('site', $site->handle)->whereIn('type', array_map(fn (ContentType $type): string => $type->handle(), $types));
        $self = $site->url($locale, $path);
        $settings = SiteSettings::entry();
        // As the pages' autodiscovery links name it.
        $title = ResolvePageMeta::feeds(ResolvePageMeta::siteName($settings), $type)[$self];
        $description = $settings?->getAttribute('default_description');

        return response()->view('kami::feed', [
            'title' => $title,
            'link' => $type?->hasArchive() === true ? $site->url($locale, Path::normalise($type->prefix())) : $site->url($locale, ''),
            'description' => is_string($description) && trim($description) !== '' ? $description : $title,
            'locale' => $locale,
            'self' => $self,
            'entries' => Listing::page($query, false, self::ITEMS, '')->items(),
            'xml' => self::xml(...),
        ], 200, ['Content-Type' => 'application/rss+xml; charset=UTF-8']);
    }

    /** The text without the characters XML 1.0 forbids, which a title or summary may hold and a reader would refuse the whole Feed for. */
    public static function xml(?string $text): string
    {
        return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $text);
    }
}
