<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Fields;

/** A value a Content Type stores, in a typed column of its `kami_type_<handle>` table (ADR 0006). */
abstract class Field
{
    /**
     * Handles a Field may not take: Kami's own attributes of an Entry, its Translation and its type table, present or planned (ADR 0007).
     *
     * A new one only arrives in a major version. A handle naming a method of `Entry`, Eloquent's included, is refused too.
     */
    public const array RESERVED_HANDLES = [
        'id', 'site', 'type', 'created_by', 'updated_by', 'created_at', 'updated_at',
        'title', 'slug', 'status', 'published_at', 'meta_title', 'meta_description', 'noindex',
        'entry_id', 'locale',
        'parent', 'parent_id', 'position', 'deleted_at',
    ];

    /** One value per Locale, or one Shared value for the whole Entry (ADR 0013). */
    protected bool $translatable = true;

    private bool $required = false;

    /** @var array<mixed> */
    private array $rules = [];

    private mixed $default = null;

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

    /** Required to publish; a Draft may leave it blank. */
    public function required(): static
    {
        $this->required = true;

        return $this;
    }

    /**
     * Laravel validation rules, applied on every save, Drafts included; a blank value passes them unless the rule is implicit.
     *
     * @param  array<mixed>  $rules
     */
    public function rules(array $rules): static
    {
        $this->rules = $rules;

        return $this;
    }

    /** The value a new Translation stores when none is given: for a Shared Field, only while the Entry has none yet. */
    public function default(mixed $value): static
    {
        $this->default = $value;

        return $this;
    }

    /** @internal */
    public function defaultValue(): mixed
    {
        return $this->default;
    }

    /** @internal */
    public function isRequired(): bool
    {
        return $this->required;
    }

    /**
     * @internal
     *
     * @return array<mixed>
     */
    public function validationRules(): array
    {
        return $this->rules;
    }

    /**
     * @internal
     *
     * The Laravel column type of its `kami_type_<handle>` column (ADR 0006).
     */
    public function column(): string
    {
        return 'string';
    }

    /** @internal */
    public function isTranslatable(): bool
    {
        return $this->translatable;
    }
}
