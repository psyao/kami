<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Fields;

/** Several lines of plain text. */
final class Textarea extends Field
{
    use TakesInlineMarkdown;

    /** @internal */
    #[\Override]
    public function column(): string
    {
        return 'text';
    }
}
