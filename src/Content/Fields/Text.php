<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Fields;

/** A single line of plain text. */
final class Text extends Field
{
    use ReadsInlineMarkdown;
}
