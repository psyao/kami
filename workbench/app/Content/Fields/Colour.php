<?php

declare(strict_types=1);

namespace Workbench\App\Content\Fields;

use Psyao\Kami\Content\Fields\Field;

/** A site's own Field type: Shared, a `string` column, hex colours only, read in lowercase. */
final class Colour extends Field
{
    #[\Override]
    protected bool $translatable = false;

    #[\Override]
    protected function baseRules(): array
    {
        return ['hex_color'];
    }

    #[\Override]
    protected function cast(mixed $value): mixed
    {
        return is_string($value) ? strtolower($value) : $value;
    }
}
