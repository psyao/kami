<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\RichText;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Psyao\Kami\Content\Internal\InlineMarkdownAllowList;

/** The value of a `Text` or `Textarea` Field taking `->inlineMarkdown()`, rendered at read time. `{{ $entry->summary }}` outputs its HTML. */
final readonly class InlineMarkdown implements Htmlable
{
    /**
     * @internal
     *
     * @param  string|null  $raw  What the column holds.
     * @param  bool  $breaksLines  Whether a newline becomes `<br>`.
     * @param  string|null  $lang  The Locale it fell back from.
     */
    public function __construct(private ?string $raw = null, private bool $breaksLines = false, private ?string $lang = null) {}

    /** @internal */
    public function inLanguage(string $lang): self
    {
        return new self($this->raw, $this->breaksLines, $lang);
    }

    /** @internal */
    public function raw(): ?string
    {
        return $this->raw;
    }

    /** The HTML, phrasing content only, in a `span` carrying `lang` when the value fell back from another Locale. */
    public function toHtml(): string
    {
        if ($this->isEmpty()) {
            return '';
        }

        return $this->lang === null ? $this->rendered() : '<span lang="' . e($this->lang) . '">' . $this->rendered() . '</span>';
    }

    /** Its words, without the syntax. */
    public function text(): string
    {
        return Str::squish(html_entity_decode(strip_tags($this->rendered()), ENT_QUOTES | ENT_HTML5));
    }

    /** Whether it holds no text. */
    public function isEmpty(): bool
    {
        return $this->text() === '';
    }

    private function rendered(): string
    {
        $options = ['html_input' => 'strip', 'allow_unsafe_links' => false];

        if ($this->breaksLines) {
            $options['renderer'] = ['soft_break' => "<br>\n"];
        }

        return rtrim((string) Str::inlineMarkdown($this->raw ?? '', $options, [new InlineMarkdownAllowList]));
    }
}
