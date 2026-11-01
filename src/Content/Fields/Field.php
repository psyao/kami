<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Fields;

use Closure;
use DateTimeInterface;

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

    private bool $indexed = false;

    private ?string $target = null;

    /** @var list<mixed>|null The target's values that show this Field; `null` shows it whenever the target is filled. */
    private ?array $targetValues = null;

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

    /** The value a new Translation stores when none is given: for a Shared Field, only when the Entry is created. */
    public function default(mixed $value): static
    {
        $this->default = $value;

        return $this;
    }

    /** A listing filters on this Field: `kami:make:migration` indexes its column. */
    public function filterable(): static
    {
        $this->indexed = true;

        return $this;
    }

    /** A listing sorts on this Field: `kami:make:migration` indexes its column. */
    public function sortable(): static
    {
        $this->indexed = true;

        return $this;
    }

    /**
     * Shown only while another Field of the type holds this value, or one of this list. Hidden, it is neither validated nor stored, and a save that gives the target clears it.
     *
     * @param  mixed  $value  A value, or a list of values.
     */
    public function visibleWhen(string $field, mixed $value): static
    {
        $this->target = $field;
        $this->targetValues = is_array($value) ? array_values($value) : [$value];

        return $this;
    }

    /** Shown only while another Field of the type holds a value; a Boolean always does, so it cannot be this target. Hidden, it is neither validated nor stored, and a save that gives the target clears it. */
    public function visibleWhenFilled(string $field): static
    {
        $this->target = $field;
        $this->targetValues = null;

        return $this;
    }

    /** @internal */
    public function conditionTarget(): ?string
    {
        return $this->target;
    }

    /**
     * @internal
     *
     * @return list<mixed>|null
     */
    public function conditionValues(): ?array
    {
        return $this->targetValues;
    }

    /**
     * @internal
     *
     * Whether the condition holds for this value of its target: read through the target's cast, as a column, a form and a declaration each spell it differently.
     */
    public function isVisibleGiven(self $target, mixed $value): bool
    {
        $values = $this->targetValues;

        if ($values === null) {
            return filled($value);
        }

        $comparable = function (mixed $value) use ($target): mixed {
            // An untouched toggle is `false` in the form and absent from an import: the same answer.
            $read = blank($value) && $target->column() === 'boolean' ? false : $target->read(blank($value) ? null : $value);

            return $read instanceof DateTimeInterface ? $read->getTimestamp() : $read;
        };

        return in_array($comparable($value), array_map($comparable, $values), true);
    }

    /** @internal */
    public function isIndexed(): bool
    {
        return $this->indexed;
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
        return [...$this->columnRules(), ...$this->baseRules(), ...$this->rules];
    }

    /**
     * @internal
     *
     * The rules of a value at this attribute, by attribute: one Field holding others validates each of them.
     *
     * @return array<string, array<mixed>>
     */
    public function rulesAt(string $attribute, bool $publishing): array
    {
        return [$attribute => [$publishing && $this->required ? 'required' : 'nullable', ...$this->validationRules()]];
    }

    /**
     * @internal
     *
     * A given value as `SaveEntry` validates it: `''` and `[]` are blank.
     */
    public function normalise(mixed $value): mixed
    {
        return $value === '' || $value === [] ? null : $value;
    }

    /** @internal */
    public function read(mixed $stored): mixed
    {
        return $stored === null ? null : $this->cast($stored);
    }

    /** @internal */
    public function write(mixed $value): mixed
    {
        return $value === null ? null : $this->store($value);
    }

    /** The column type of its `kami_type_<handle>` column: `string`, `text`, `boolean`, `integer`, `decimal`, `date`, `dateTime` or `json` (ADR 0006). */
    public function column(): string
    {
        return 'string';
    }

    /** @internal */
    public function isTranslatable(): bool
    {
        return $this->translatable;
    }

    /**
     * The rules every value of this Field type passes, before those of `->rules()`.
     *
     * @return array<mixed>
     */
    protected function baseRules(): array
    {
        return [];
    }

    /**
     * What the smallest of the four engines' columns holds, so a longer value is a validation error on every engine.
     *
     * @return array<mixed>
     */
    private function columnRules(): array
    {
        return match ($this->column()) {
            'string' => [$this->lengthRule(255, mb_strlen(...))],
            // MySQL's `text` counts bytes.
            'text' => [$this->lengthRule(65535, strlen(...))],
            default => [],
        };
    }

    /**
     * Not Laravel's `max`, which beside `numeric` compares the number itself.
     *
     * @param  Closure(string): int  $length
     */
    private function lengthRule(int $max, Closure $length): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($max, $length): void {
            if (is_array($value)) {
                $fail(__('validation.string'));
            } elseif (is_string($value) && $length($value) > $max) {
                $fail(__('validation.max.string', ['max' => $max]));
            }
        };
    }

    /** What its column stores for a valid value given to `SaveEntry`; never called with `null`. */
    protected function store(mixed $value): mixed
    {
        return $value;
    }

    /** The value `$entry->handle` returns for what its column stores; never called with `null`. */
    protected function cast(mixed $value): mixed
    {
        return $value;
    }
}
