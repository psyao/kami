<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Fields;

use Closure;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Sites\Internal\Site;

/**
 * Links to other Entries, in pick order, kept in `kami_references` with no column in the type's table (ADR 0005).
 *
 * What `Entries` and `Terms` share, public only because they are (ADR 0010): a site uses those and does not extend it.
 */
abstract class RelationField extends Field
{
    /** @internal */
    #[\Override]
    protected bool $translatable = false;

    /** @var list<class-string<ContentType>> */
    private array $targets = [];

    /**
     * The Content Types it links to.
     *
     * @param  class-string<ContentType>  ...$types
     */
    public function of(string ...$types): static
    {
        $this->targets = array_values($types);

        return $this;
    }

    /**
     * @internal
     *
     * @return list<class-string<ContentType>>
     */
    public function targets(): array
    {
        return $this->targets;
    }

    /**
     * @internal
     *
     * @return list<string>
     */
    public function targetHandles(): array
    {
        $types = resolve(ContentTypes::class);

        return array_map(fn (string $class): string => $types->find($class)?->handle() ?? $class, $this->targets);
    }

    /** @internal No column: every reader of the type's table goes through `ContentType::columnFields()`. */
    #[\Override]
    public function column(): string
    {
        return 'none';
    }

    /**
     * @internal
     *
     * A form's list may arrive with other keys.
     */
    #[\Override]
    public function normalise(mixed $value): mixed
    {
        return parent::normalise(is_array($value) ? array_values($value) : $value);
    }

    /**
     * @internal
     *
     * @return array<mixed>
     */
    #[\Override]
    protected function baseRules(): array
    {
        return ['array', $this->targetsRule()];
    }

    /** On the Field itself, in one query: an error names the Field, not a row. */
    private function targetsRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                return;
            }

            $ids = array_filter($value, is_string(...));

            if (count(array_unique($ids)) !== count($value)) {
                $fail(__(count($ids) === count($value) ? 'validation.distinct' : 'validation.exists'));

                return;
            }

            $found = Entry::query()->withUnpublished()->whereIn('id', $ids)->where('site', Site::current()->handle)->whereIn('type', $this->targetHandles())->count();

            if ($found !== count($ids)) {
                $fail(__('validation.exists'));
            }
        };
    }
}
