<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Fields;

use Closure;
use Psyao\Kami\Content\Layout;

/** One set of named sub-Fields, read as an array, `null` when all are blank. Shared by default, in one `json` column. */
final class Group extends StructuredField
{
    /** @param  array<Field|Layout>  $fields  Its sub-Fields, each storing under its handle. */
    #[\Override]
    public static function make(string $handle, array $fields = []): static
    {
        return parent::make($handle)->holding($fields);
    }

    /** @internal */
    #[\Override]
    public function normalise(mixed $value): mixed
    {
        $normalised = parent::normalise($value);

        return is_array($normalised) && array_filter($normalised, fn (mixed $sub): bool => $sub !== null) === [] ? null : $normalised;
    }

    #[\Override]
    protected function rowAttribute(string $attribute): string
    {
        return $attribute;
    }

    #[\Override]
    protected function mapped(array $value, Closure $callback): array
    {
        return $this->mappedRow($value, $callback);
    }
}
