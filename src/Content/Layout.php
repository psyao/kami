<?php

declare(strict_types=1);

namespace Psyao\Kami\Content;

use Psyao\Kami\Content\Fields\Field;

/** Arranges Fields in the form and holds no value: columns, validation and reads ignore it (ADR 0007). */
abstract class Layout
{
    /** @param list<Field|Layout> $children */
    final public function __construct(public readonly string $handle, public readonly array $children) {}

    /** @param list<Field|Layout> $children */
    public static function make(string $handle, array $children): static
    {
        return new static($handle, $children);
    }
}
