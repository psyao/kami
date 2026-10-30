<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Fields;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/** A calendar day, read as a `CarbonImmutable` at midnight UTC. It has no time, so no time zone converts it: the day given is the day stored. */
final class Date extends Field
{
    #[\Override]
    protected bool $translatable = false;

    #[\Override]
    public function column(): string
    {
        return 'date';
    }

    #[\Override]
    protected function baseRules(): array
    {
        return ['date'];
    }

    /** The day as given, in the zone it was given in. */
    #[\Override]
    protected function store(mixed $value): string
    {
        /** @var DateTimeInterface|string $value */
        return CarbonImmutable::parse($value)->format('Y-m-d');
    }

    #[\Override]
    protected function cast(mixed $value): CarbonImmutable
    {
        /** @var DateTimeInterface|string $value */
        return CarbonImmutable::parse($value, 'UTC');
    }
}
