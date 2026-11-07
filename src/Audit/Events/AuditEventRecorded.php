<?php

declare(strict_types=1);

namespace Psyao\Kami\Audit\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An Audit event was recorded through `RecordAuditEvent`, once the write committed (ADR 0029): a site may alert on it or forward it elsewhere. A batch dispatches it for its summary only. It carries the row's values.
 */
final readonly class AuditEventRecorded implements ShouldDispatchAfterCommit
{
    /**
     * @param  string|null  $subjectType  A short type name, such as `entry`, or a model's morph class.
     * @param  array<string, scalar|null>  $context
     * @param  Authenticatable|null  $actor  Null for the CLI and the system.
     */
    public function __construct(
        public string $event,
        public ?string $subjectType,
        public ?string $subjectId,
        public ?string $site,
        public array $context,
        public ?Authenticatable $actor,
    ) {}
}
