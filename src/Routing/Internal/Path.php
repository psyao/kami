<?php

declare(strict_types=1);

namespace Psyao\Kami\Routing\Internal;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Normalizer;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Sites\Internal\Site;

/**
 * An address Kami answers in a Site and Locale: the current Path of a Translation (status 200), a 301 or 302 to an Entry or a free `target`, or a 410 (ADR 0008).
 * Site code reads the non-200 rows through the public `Redirect`.
 *
 * @internal
 *
 * @property string $id
 * @property string $site
 * @property string $locale
 * @property string $path
 * @property string|null $entry_id
 * @property string|null $target
 * @property int $status
 */
#[Unguarded]
#[Table(name: 'kami_paths')]
final class Path extends Model
{
    use HasUlids;

    /** The longest Path `kami_paths.path` holds. */
    public const int MAX_LENGTH = 500;

    /** First segments Kami's own routes answer under: Media Conversions (ADR 0017), the sitemap's files (ADR 0027), Preview links. No Path starts with one. */
    public const array RESERVED_SEGMENTS = ['media', 'sitemaps', 'kami'];

    /** Whole Paths Kami's own files answer: the sitemap index and the managed `robots.txt` (ADR 0027). */
    public const array RESERVED_PATHS = ['sitemap.xml', 'robots.txt'];

    /** The query pairs a request is resolved with, the first once sorted: past them, parameters are ignored, which bounds `RenderEntry`'s `IN` list. A source holds no more. */
    public const int MAX_QUERY_PAIRS = 20;

    /** `https://`, `http://`, a path on the Site but not `//…`, a fragment, `mailto:` and `tel:`, with no whitespace. */
    private const string LINKABLE = '~^(?:https?://[^\s/?#]+[^\s]*|/(?!/)[^\s]*|#[^\s]*|mailto:[^\s]+|tel:[^\s]+)$~i';

    /** The Path of a Translation: its type's Prefix, then its slug; a Singleton's is its Prefix; none with no URL or no slug yet. */
    public static function of(ContentType $type, ?string $slug): ?string
    {
        if (! $type->isRoutable()) {
            return null;
        }

        if (! $type->hasSlug()) {
            return self::normalise($type->prefix());
        }

        return $slug === null ? null : self::normalise("{$type->prefix()}/{$slug}");
    }

    /** Whether a normalised Path is one Kami answers itself, which hides an Entry's in the default Locale (ADR 0008). */
    public static function isReserved(string $path): bool
    {
        return in_array($path, self::RESERVED_PATHS, true) || in_array(explode('/', $path)[0], self::RESERVED_SEGMENTS, true);
    }

    /**
     * Why a normalised Path cannot be an address in a Locale, or null: Kami answers it itself, an Archive or a Feed does, or it starts with another
     * Locale's code in the default Locale, which `ResolveLocale` would read as that Locale (ADR 0008). `kami:doctor` reports a stored Path one declared later hides.
     */
    public static function refusal(string $path, Site $site, string $locale): ?string
    {
        if (self::isReserved($path) || self::archiveAt($path) instanceof ContentType || self::feedAt($path) !== null) {
            return __('kami::admin.fields.slug_reserved');
        }

        if (in_array($locale, [$site->defaultLocale(), '*'], true) && in_array($segment = explode('/', $path)[0], array_slice($site->locales, 1), true)) {
            return __('kami::admin.fields.slug_locale', ['locale' => $segment]);
        }

        return null;
    }

    /** Whether an address may be linked to: one a Menu item or a Redirect points to. A backslash never: browsers read `/\host` as `//host`. */
    public static function isLinkable(string $address): bool
    {
        return preg_match(self::LINKABLE, $address) === 1 && ! str_contains($address, '\\');
    }

    /**
     * A Redirect source in its stored form: the Path normalised, then its query string normalised, `blog?p=123` (ADR 0008).
     *
     * @return array{string, string, int} The Path, the source with its query string, and its number of query pairs.
     */
    public static function source(string $source): array
    {
        [$path, $query] = self::split($source);
        $path = self::normalise($path);
        $pairs = self::pairs($query);

        return [$path, $pairs === [] ? $path : $path . '?' . implode('&', $pairs), count($pairs)];
    }

    /**
     * An address's path and query string, its fragment left out.
     *
     * @return array{string, string}
     */
    public static function split(string $address): array
    {
        $parts = explode('?', explode('#', $address, 2)[0], 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    /**
     * The stored addresses a request may match, most specific first: the Path with its whole query string, with each pair alone, with each key
     * alone (`?p` matches any value), then the Path alone. Other parameters are ignored (ADR 0008, #630).
     *
     * @return non-empty-list<string>
     */
    public static function candidates(string $path, string $query): array
    {
        $pairs = array_slice(self::pairs($query), 0, self::MAX_QUERY_PAIRS);

        if ($pairs === []) {
            return [$path];
        }

        $keys = array_map(fn (string $pair): string => explode('=', $pair, 2)[0], $pairs);
        $queries = array_unique([implode('&', $pairs), ...array_filter($pairs, fn (string $pair): bool => str_contains($pair, '=')), ...$keys]);

        return [...array_map(fn (string $query): string => "{$path}?{$query}", array_values($queries)), $path];
    }

    /**
     * A query string's pairs, sorted: each key and value percent-decoded (`+` as a space), lowercase and NFC, then encoded again; a key with no `=` keeps none.
     *
     * @return list<string>
     */
    private static function pairs(string $query): array
    {
        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair !== '') {
                $pairs[] = array_map(function (string $part): string {
                    $part = mb_strtolower(urldecode($part));
                    $normal = Normalizer::normalize($part);

                    return rawurlencode(is_string($normal) ? $normal : $part);
                }, explode('=', $pair, 2));
            }
        }

        // By key, a key alone first, then by value.
        usort($pairs, fn (array $a, array $b): int => [$a[0], count($a), $a[1] ?? ''] <=> [$b[0], count($b), $b[1] ?? '']);

        return array_map(fn (array $pair): string => implode('=', $pair), $pairs);
    }

    /** The type whose Archive answers at a normalised Path, in every Locale, which hides an Entry's there (ADR 0009). */
    public static function archiveAt(string $path): ?ContentType
    {
        return resolve(ContentTypes::class)->archives()[$path] ?? null;
    }

    /**
     * The types whose Feed answers at a normalised Path, in every Locale, which hides an Entry's there: every type with a Feed at `feed`.
     *
     * @return non-empty-list<ContentType>|null
     */
    public static function feedAt(string $path): ?array
    {
        return resolve(ContentTypes::class)->feeds()[$path] ?? null;
    }

    /** The normalised Path a type's own Feed answers at. */
    public static function feedOf(ContentType $type): string
    {
        return self::normalise($type->prefix()) . '/feed';
    }

    /** The request's normalised path without its Locale segment, which `ResolveLocale` read and no stored Path holds. */
    public static function requested(Request $request): string
    {
        $site = Site::current();
        $locale = $site->currentLocale();

        return self::normalise($locale === $site->defaultLocale() ? $request->path() : substr($request->path(), strlen($locale) + 1));
    }

    /** The slug a title gives in a Locale, the same in the admin form and on save. */
    public static function slugFrom(string $title, string $locale): string
    {
        return self::normalise(Str::slug($title, '-', $locale));
    }

    /** Whether a normalised slug can answer at its URL: invalid UTF-8, a character a URL cannot carry there, an empty or dot segment cannot. */
    public static function isAnswerable(string $slug): bool
    {
        return preg_match('~[\s\p{C}?#%\\\\]|//|(^|/)\.\.?(/|$)~u', $slug) === 0;
    }

    /** The stored form: percent-decoded, lowercase, NFC, without leading or trailing slash. */
    public static function normalise(string $path): string
    {
        $path = mb_strtolower(trim(rawurldecode($path), '/'));

        // Invalid UTF-8 has no normal form: it matches no stored Path either way.
        $normal = Normalizer::normalize($path);

        return is_string($normal) ? $normal : $path;
    }
}
