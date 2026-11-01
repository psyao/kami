<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Fields;

use Psyao\Kami\Content\RichText\InlineMarkdown;

/** What `Text` and `Textarea` share, public only because they are: on another Field type, `->inlineMarkdown()` is an undefined method. */
trait ReadsInlineMarkdown
{
    private bool $inlineMarkdown = false;

    /** Stored raw and edited raw, read as an `InlineMarkdown`, even when blank: emphasis, strong, inline code and `http`, `https` or `mailto` links. */
    public function inlineMarkdown(): static
    {
        $this->inlineMarkdown = true;

        return $this;
    }

    /** @internal */
    #[\Override]
    public function read(mixed $stored): mixed
    {
        if (! $this->inlineMarkdown) {
            return parent::read($stored);
        }

        return new InlineMarkdown(is_scalar($stored) ? (string) $stored : null, $this->column() === 'text');
    }
}
