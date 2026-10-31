<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Fields;

use Closure;
use Psyao\Kami\Content\Layout;

/** A variable list of rows of sub-Fields, read as a list of arrays, `null` with no row. Shared by default, in one `json` column. */
final class Repeater extends StructuredField
{
    /** @param  array<Field|Layout>  $fields  Its sub-Fields, each storing under its handle. */
    #[\Override]
    public static function make(string $handle, array $fields = []): static
    {
        return parent::make($handle)->holding($fields);
    }

    /** @internal */
    #[\Override]
    public function rulesAt(string $attribute, bool $publishing): array
    {
        return ["{$attribute}.*" => ['array'], ...parent::rulesAt($attribute, $publishing)];
    }

    #[\Override]
    protected function baseRules(): array
    {
        return [...parent::baseRules(), 'list'];
    }

    #[\Override]
    protected function rowAttribute(string $attribute): string
    {
        return "{$attribute}.*";
    }

    #[\Override]
    protected function mapped(array $value, Closure $callback): array
    {
        return array_map(fn (mixed $row): mixed => is_array($row) ? parent::mapped($row, $callback) : $row, $value);
    }
}
