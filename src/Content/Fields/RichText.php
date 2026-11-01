<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Fields;

use Psyao\Kami\Content\Internal\RichTextNormaliser;
use Psyao\Kami\Content\RichText\Document;

/** Formatted text and links, in one `json` column, read as a `Document` even when blank. `SaveEntry` normalises it against what this Field allows (ADR 0016). */
final class RichText extends Field
{
    private const array HEADING_LEVELS = [2, 3, 4];

    private bool $code = false;

    /** @var array<mixed> As declared: `ContentTypes` refuses any other level. */
    private array $headings = self::HEADING_LEVELS;

    /** Adds inline code and a code block with an optional `language`, with no server highlighting. */
    public function code(): static
    {
        $this->code = true;

        return $this;
    }

    /**
     * Narrows the heading levels, among 2, 3 and 4: the title is the page's H1.
     *
     * @param  array<mixed>  $levels
     */
    public function headings(array $levels): static
    {
        $this->headings = $levels;

        return $this;
    }

    /** @internal */
    public function allowsCode(): bool
    {
        return $this->code;
    }

    /**
     * @internal
     *
     * @return list<int>
     */
    public function headingLevels(): array
    {
        return array_values(array_filter($this->headings, is_int(...)));
    }

    /** @internal */
    public function hasValidHeadings(): bool
    {
        return $this->headings !== [] && array_filter($this->headings, fn (mixed $level): bool => ! in_array($level, self::HEADING_LEVELS, true)) === [];
    }

    /** @internal */
    #[\Override]
    public function column(): string
    {
        return 'json';
    }

    /**
     * @internal
     *
     * A document holding nothing is `null`, so `required()` and the Fallback read it as blank.
     */
    #[\Override]
    public function normalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return parent::normalise($value);
        }

        $document = new RichTextNormaliser($this->code, $this->headingLevels())->document($value);

        return new Document($document)->isEmpty() ? null : $document;
    }

    /** @internal */
    #[\Override]
    public function read(mixed $stored): Document
    {
        return new Document($this->decoded($stored));
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
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
