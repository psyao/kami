<?php

declare(strict_types=1);

namespace Psyao\Kami\Audit\Internal;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row of the Audit log, read by the admin and deleted by `kami:audit:prune` only: `RecordAuditEvent` is its one writer (ADR 0022).
 *
 * @internal
 *
 * @property-read string $id
 * @property-read Carbon $occurred_at
 * @property-read int|string|null $user_id
 * @property-read string $actor `user`, `cli` or `system`.
 * @property-read string $event
 * @property-read string|null $subject_type
 * @property-read string|null $subject_id
 * @property-read string|null $site
 * @property-read string|null $ip
 * @property-read array<string, bool|float|int|string|null> $context
 */
#[Table(name: 'kami_audit_events')]
#[WithoutTimestamps]
final class AuditEvent extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'context' => 'array'];
    }
}
