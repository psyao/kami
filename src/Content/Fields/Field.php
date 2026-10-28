<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Fields;

/** A value a Content Type stores, in a typed column of its `kami_type_<handle>` table (ADR 0006). */
abstract class Field
{
    /** One value per Locale, or one Shared value for the whole Entry (ADR 0013). */
    protected bool $translatable = true;

    final public function __construct(public readonly string $handle) {}

    public static function make(string $handle): static
    {
        return new static($handle);
    }

    /** One value for the whole Entry, whatever the Locale. */
    public function shared(): static
    {
        $this->translatable = false;

        return $this;
    }

    /** One value per Locale, falling back to the default Locale's when blank. */
    public function translatable(): static
    {
        $this->translatable = true;

        return $this;
    }

    /** @internal */
    public function isTranslatable(): bool
    {
        return $this->translatable;
    }
}
