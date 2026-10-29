<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Fields;

/** Several lines of plain text. */
final class Textarea extends Field
{
    #[\Override]
    protected string $column = 'text';
}
