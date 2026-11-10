<?php

declare(strict_types=1);

namespace Psyao\Kami\Forms\Internal;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Psyao\Kami\Forms\Submission;

/**
 * A Submission's notification through one channel: pending until `delivered_at`, abandoned after `MAX_ATTEMPTS`.
 *
 * @internal
 *
 * @property string $id
 * @property string $submission_id
 * @property string $channel A channel name of the notification's `via()`, such as `mail`, or a channel class.
 * @property int $attempts
 * @property CarbonImmutable|null $delivered_at
 * @property string|null $last_error
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Submission $submission
 */
#[Unguarded]
#[Table(name: 'kami_form_deliveries')]
final class Delivery extends Model
{
    use HasUlids;

    public const int MAX_ATTEMPTS = 5;

    /**
     * Not delivered yet, nor abandoned.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->whereNull('delivered_at')->where('attempts', '<', self::MAX_ATTEMPTS);
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'delivered_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
