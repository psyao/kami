<?php

declare(strict_types=1);

namespace Psyao\Kami\Content;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\InlinesOnly\InlinesOnlyExtension;
use League\CommonMark\MarkdownConverter;
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
    public function __construct(private ?string $raw, private bool $breaksLines, private ?string $lang = null) {}

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
        $html = $this->rendered();

        if ($this->wordsOf($html) === '') {
            return '';
        }

        return $this->lang === null ? $html : '<span lang="' . e($this->lang) . '">' . $html . '</span>';
    }

    /** Its words, without the syntax. */
    public function text(): string
    {
        return $this->wordsOf($this->rendered());
    }

    /** Whether it holds no text. */
    public function isEmpty(): bool
    {
        return $this->text() === '';
    }

    private function wordsOf(string $html): string
    {
        return Str::squish(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
    }

    private function rendered(): string
    {
        $environment = new Environment(['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $environment->addExtension(new InlinesOnlyExtension);
        $environment->addExtension(new InlineMarkdownAllowList);

        $html = rtrim(new MarkdownConverter($environment)->convert($this->raw ?? '')->getContent());

        // A soft break and a blank line each come out as newlines; a hard break already holds its `<br />`.
        return $this->breaksLines ? (string) preg_replace('/(?<!<br \/>)\n/', "<br>\n", $html) : $html;
    }
}
