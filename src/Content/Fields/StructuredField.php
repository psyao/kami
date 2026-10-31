<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Fields;

use Closure;
use Psyao\Kami\Content\Layout;

/**
 * Sub-Fields kept in one `json` column, an opaque document no SQL looks into, always handled decoded (ADR 0006).
 *
 * What `Repeater` and `Group` share, public only because they are (ADR 0010): a site uses those two and does not extend it.
 */
abstract class StructuredField extends Field
{
    /** @internal */
    #[\Override]
    protected bool $translatable = false;

    /** @var array<Field|Layout> As declared: `ContentTypes` refuses a Layout. */
    private array $subFields = [];

    /**
     * @internal
     *
     * @param  array<Field|Layout>  $fields
     */
    final protected function holding(array $fields): static
    {
        $this->subFields = $fields;

        return $this;
    }

    /**
     * @internal
     *
     * @return array<Field|Layout>
     */
    public function subFields(): array
    {
        return $this->subFields;
    }

    /**
     * @internal
     *
     * @return array<string, Field> Its sub-Fields by handle, once `ContentTypes` has refused a Layout among them.
     */
    public function fields(): array
    {
        $fields = [];

        foreach ($this->subFields as $field) {
            if ($field instanceof Field) {
                $fields[$field->handle] = $field;
            }
        }

        return $fields;
    }

    /** @internal */
    #[\Override]
    public function column(): string
    {
        return 'json';
    }

    /** @internal */
    #[\Override]
    public function rulesAt(string $attribute, bool $publishing): array
    {
        $rules = parent::rulesAt($attribute, $publishing);

        foreach ($this->fields() as $handle => $field) {
            $rules = [...$rules, ...$field->rulesAt("{$this->rowAttribute($attribute)}.{$handle}", $publishing)];
        }

        return $rules;
    }

    /**
     * @internal
     *
     * Only declared sub-Fields stay, each blank one `null`. No row goes away: an error's index names its row.
     */
    #[\Override]
    public function normalise(mixed $value): mixed
    {
        return parent::normalise(is_array($value) ? $this->mapped($value, fn (Field $field, mixed $sub): mixed => $field->normalise($sub)) : $value);
    }

    /**
     * @internal
     *
     * The document a column holds, as arrays and scalars.
     *
     * @return array<mixed>|null
     */
    final public function decoded(mixed $stored): ?array
    {
        $decoded = is_string($stored) ? json_decode($stored, true) : $stored;

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @internal
     *
     * The key under which a sub-Field's rules apply.
     */
    protected function rowAttribute(string $attribute): string
    {
        return $attribute;
    }

    /**
     * @internal
     *
     * Applies a closure to each sub-Field's value.
     *
     * @param  array<mixed>  $value
     * @param  Closure(Field, mixed): mixed  $callback
     * @return array<mixed>
     */
    protected function mapped(array $value, Closure $callback): array
    {
        return array_map(fn (Field $field): mixed => $callback($field, $value[$field->handle] ?? null), $this->fields());
    }

    /** @internal */
    #[\Override]
    protected function baseRules(): array
    {
        return ['array'];
    }

    /** @internal */
    #[\Override]
    protected function store(mixed $value): string
    {
        return json_encode($this->document($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @internal
     *
     * @return array<mixed>|null
     */
    #[\Override]
    protected function cast(mixed $value): ?array
    {
        $decoded = $this->decoded($value);

        return $decoded === null ? null : $this->mapped($decoded, fn (Field $field, mixed $sub): mixed => $field->read($sub));
    }

    /** @return array<mixed> */
    private function document(mixed $value): array
    {
        /** @var array<mixed> $value */
        return $this->mapped($value, fn (Field $field, mixed $sub): mixed => $field instanceof self ? ($sub === null ? null : $field->document($sub)) : $field->write($sub));
    }
}
