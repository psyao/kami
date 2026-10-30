<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Fields;

/** A whole number read as an `int`, or with `->decimal()` a number with two decimals read as a `string`, which stays exact. */
final class Number extends Field
{
    #[\Override]
    protected bool $translatable = false;

    private bool $decimal = false;

    /** A number with up to two decimals, up to 999999.99. */
    public function decimal(): self
    {
        // ponytail: Laravel's default `decimal(8,2)`, ceiling 999999.99; a precision argument on a friction issue asking for more.
        $this->decimal = true;

        return $this;
    }

    #[\Override]
    public function column(): string
    {
        return $this->decimal ? 'decimal' : 'integer';
    }

    /** Within the column's range, so a value out of it is a validation error and not a database one. */
    #[\Override]
    protected function baseRules(): array
    {
        return $this->decimal
            ? ['numeric', 'decimal:0,2', 'between:-999999.99,999999.99']
            : ['integer', 'between:-2147483648,2147483647'];
    }

    /** SQLite hands `12.5` back for `12.50`: formatted, a decimal reads the same on every engine. */
    #[\Override]
    protected function cast(mixed $value): int | string
    {
        /** @var int|float|string $value */
        return $this->decimal ? number_format((float) $value, 2, '.', '') : (int) $value;
    }
}
