<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Fields;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/** A moment, read as a `CarbonImmutable`, stored in UTC and shown in the Site's time zone in the admin, as `published_at` (ADR 0004). */
final class DateTime extends Field
{
    #[\Override]
    protected bool $translatable = false;

    #[\Override]
    public function column(): string
    {
        return 'dateTime';
    }

    #[\Override]
    protected function baseRules(): array
    {
        return ['date'];
    }

    #[\Override]
    protected function store(mixed $value): string
    {
        /** @var DateTimeInterface|string $value */
        return CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s');
    }

    #[\Override]
    protected function cast(mixed $value): CarbonImmutable
    {
        /** @var DateTimeInterface|string $value */
        return CarbonImmutable::parse($value, 'UTC');
    }
}
