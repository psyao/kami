<?php

declare(strict_types=1);

namespace Psyao\Kami\Redirects;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Psyao\Kami\Content\Entry;

/**
 * An address of a Site and Locale that answers 301 or 302 to an Entry's current URL or to a free address, or 410: a non-200 row of `kami_paths` (ADR 0008).
 * Written by `SaveRedirect` and `DeleteRedirect`, and by Kami when a once-public Path moves or an Entry is deleted.
 *
 * @property string $id
 * @property string $site
 * @property string $locale
 * @property string $path The source, without the Locale segment, in ADR 0008's canonical form, with its normalised query string: `blog`, `?p=123`, `?attachment_id`.
 * @property int $status 301, 302 or 410.
 * @property string|null $entry_id The target Entry, sent to its current URL.
 * @property string|null $target The target address, sent as stored.
 * @property CarbonImmutable|null $created_at Null for a row Kami wrote before 0.10.0.
 * @property CarbonImmutable|null $updated_at
 * @property-read Entry|null $entry The target Entry, when it is visible.
 */
#[Unguarded]
#[Table(name: 'kami_paths')]
final class Redirect extends Model
{
    use HasUlids;

    /** @return BelongsTo<Entry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }

    protected static function booted(): void
    {
        self::addGlobalScope('redirects', fn (Builder $query): Builder => $query->where('status', '!=', 200));
    }

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
