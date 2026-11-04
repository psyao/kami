<?php

declare(strict_types=1);

namespace Psyao\Kami\Media\Internal;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A Media item's alt text and caption in one Locale (ADR 0013).
 *
 * @property string $media_id
 * @property string $locale
 * @property string|null $alt
 * @property string|null $caption
 *
 * @internal
 */
#[Unguarded]
#[WithoutTimestamps]
#[Table(name: 'kami_media_translations')]
final class MediaTranslation extends Model
{
    use HasUlids;

    /** The attributes `SaveMedia` writes per Locale. */
    public const array ATTRIBUTES = ['alt', 'caption'];
}
