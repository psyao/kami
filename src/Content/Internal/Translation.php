<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Psyao\Kami\Content\Entry;

/**
 * The part of an Entry that belongs to one Locale, with its own publication (ADR 0013, ADR 0019).
 *
 * @internal
 *
 * @property string $id
 * @property string $entry_id
 * @property string $locale
 * @property string|null $title
 * @property string|null $slug
 * @property string|null $status
 * @property CarbonImmutable|null $published_at
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property bool $noindex
 * @property-read Entry $entry
 */
#[Unguarded]
#[Table(name: 'kami_translations')]
final class Translation extends Model
{
    use HasUlids;

    /** The attributes an Entry reads from its Translation. */
    public const array ATTRIBUTES = ['title', 'slug', 'status', 'published_at', ...self::SEO];

    /** What it says to search engines, on a routable type only (#23 decision 1). */
    public const array SEO = ['meta_title', 'meta_description', 'noindex'];

    /** The two stored statuses; Scheduled is a published Translation whose date is to come (ADR 0019). */
    public const string DRAFT = 'draft';

    public const string PUBLISHED = 'published';

    /**
     * Read outside the Visibility scope: a Translation's Entry is never hidden from it.
     *
     * @return BelongsTo<Entry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class, 'entry_id')->withUnpublished();
    }

    protected function casts(): array
    {
        return ['published_at' => 'immutable_datetime', 'noindex' => 'boolean'];
    }
}
