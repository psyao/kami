<?php

declare(strict_types=1);

namespace Psyao\Kami\Forms;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Psyao\Kami\Forms\Internal\Delivery;

/**
 * What a visitor sent through a Form: its validated values only, with no IP, user agent or cookie (ADR 0015). Written by `StoreSubmission`.
 *
 * @property string $id
 * @property string $site
 * @property string $locale The Locale of the page it was sent from.
 * @property string $form The Form's handle.
 * @property string|null $entry_id The page it was sent from; null once that Entry is deleted.
 * @property array<string, mixed> $data
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Unguarded]
#[Table(name: 'kami_form_submissions')]
final class Submission extends Model
{
    use HasUlids;

    /** Marks it read, once: a later call keeps the first `read_at`. Reading is not an Audit event (ADR 0022). */
    public function markRead(): void
    {
        if ($this->read_at !== null) {
            return;
        }

        // A conditional update, not a save: two editors opening it at once keep the first `read_at`.
        $now = now()->toImmutable();
        self::query()->whereKey($this->id)->whereNull('read_at')->update(['read_at' => $now]);
        $this->read_at = $now;
        $this->syncOriginalAttribute('read_at');
    }

    /**
     * @internal
     *
     * @return HasMany<Delivery, $this>
     */
    public function deliveries(): HasMany
    {
        // By channel: the admin lists them in a stable order on every engine.
        return $this->hasMany(Delivery::class)->orderBy('channel');
    }

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
