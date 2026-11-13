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

    /** Seconds after which a claim with no outcome is an attempt PHP stopped mid-send, retried as a failure. */
    public const int CLAIM_TIMEOUT = 3600;

    /** Not delivered after `MAX_ATTEMPTS`: no run retries it. `abandoned()` is the same rule in a query. */
    public function isAbandoned(): bool
    {
        return $this->delivered_at === null && $this->attempts >= self::MAX_ATTEMPTS;
    }

    /**
     * Not delivered after `MAX_ATTEMPTS`: `isAbandoned()` in a query.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function abandoned(Builder $query): void
    {
        $query->whereNull('delivered_at')->where('attempts', '>=', self::MAX_ATTEMPTS);
    }

    /**
     * Not delivered yet, nor abandoned.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->whereNull('delivered_at')->whereNot(fn (Builder $query): Builder => $query->abandoned());
    }

    /**
     * Claimed by a sender whose attempt has no outcome yet: `Deliveries::send()` clears `last_error` as it claims, and records one when the attempt fails.
     * After `CLAIM_TIMEOUT`, PHP is taken to have stopped the attempt.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function sending(Builder $query): void
    {
        $query->where('attempts', '>', 0)->whereNull('delivered_at')->whereNull('last_error')
            ->where('updated_at', '>', now()->subSeconds(self::CLAIM_TIMEOUT));
    }

    /**
     * Pending, and no sender holds it: what `kami:forms:deliver` tries.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function due(Builder $query): void
    {
        $query->pending()->whereNot(fn (Builder $query): Builder => $query->sending());
    }

    /**
     * Due after a failed attempt, or one PHP stopped: what `kami:forms:deliver` names as waiting and `kami:doctor` reports.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function waiting(Builder $query): void
    {
        $query->due()->where('attempts', '>', 0);
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
