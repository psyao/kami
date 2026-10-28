<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Fields;

/** A value a Content Type stores, in a typed column of its `kami_type_<handle>` table (ADR 0006). */
abstract class Field
{
    final public function __construct(public readonly string $handle) {}

    public static function make(string $handle): static
    {
        return new static($handle);
    }
}
