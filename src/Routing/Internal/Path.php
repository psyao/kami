<?php

declare(strict_types=1);

namespace Psyao\Kami\Routing\Internal;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Normalizer;
use Psyao\Kami\Content\ContentType;

/**
 * An address Kami answers in a Site and Locale: the current Path of a Translation (status 200) for now (ADR 0008).
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
#[WithoutTimestamps]
final class Path extends Model
{
    use HasUlids;

    /** The Path of a Translation: its type's Prefix, then its slug. */
    public static function of(ContentType $type, string $slug): string
    {
        return self::normalise("{$type->prefix()}/{$slug}");
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
