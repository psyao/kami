<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The part of an Entry that belongs to one Locale, with its own publication (ADR 0013, ADR 0019).
 *
 * @internal
 *
 * @property string $id
 * @property string $entry_id
 * @property string $locale
 * @property string $title
 * @property string|null $slug
 * @property string|null $status
 * @property CarbonImmutable|null $published_at
 */
#[Unguarded]
#[Table(name: 'kami_translations')]
final class Translation extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return ['published_at' => 'immutable_datetime'];
    }
}
