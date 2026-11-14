<?php

declare(strict_types=1);

namespace Psyao\Kami\Routing\Internal;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Normalizer;
use Psyao\Kami\Content\ContentType;

/**
 * An address Kami answers in a Site and Locale: the current Path of a Translation (status 200), a 301 to the Entry replacing a deleted one, or a 410 (ADR 0008).
 *
 * @internal
 *
 * @property string $id
 * @property string $site
 * @property string $locale
 * @property string $path
 * @property string|null $entry_id
 * @property int $status
 */
#[Unguarded]
#[Table(name: 'kami_paths')]
#[WithoutTimestamps]
final class Path extends Model
{
    use HasUlids;

    /** The longest Path `kami_paths.path` holds. */
    public const int MAX_LENGTH = 500;

    /** First segments Kami's own routes answer under: Media Conversions (ADR 0017), the sitemap's files (ADR 0027), Preview links. No Path starts with one. */
    public const array RESERVED_SEGMENTS = ['media', 'sitemaps', 'kami'];

    /** Whole Paths Kami's own files answer: the sitemap index and the managed `robots.txt` (ADR 0027). */
    public const array RESERVED_PATHS = ['sitemap.xml', 'robots.txt'];

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
