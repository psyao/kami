<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\RichText;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Psyao\Kami\Content\Fields\RichText;
use Psyao\Kami\Content\Internal\RichTextHtml;
use Tiptap\Core\Extension;
use Tiptap\Editor;
use Tiptap\Marks\Bold;
use Tiptap\Marks\Code;
use Tiptap\Marks\Italic;
use Tiptap\Marks\Link;
use Tiptap\Nodes\Blockquote;
use Tiptap\Nodes\BulletList;
use Tiptap\Nodes\CodeBlock;
use Tiptap\Nodes\Document as RootNode;
use Tiptap\Nodes\HardBreak;
use Tiptap\Nodes\Heading;
use Tiptap\Nodes\HorizontalRule;
use Tiptap\Nodes\ListItem;
use Tiptap\Nodes\OrderedList;
use Tiptap\Nodes\Paragraph;
use Tiptap\Nodes\Text;

/** A `RichText` Field's value, rendered at read time. `{{ $entry->body }}` outputs its HTML. */
final readonly class Document implements Htmlable
{
    /**
     * @internal
     *
     * @param  array<mixed>|null  $content  What the column holds, decoded.
     * @param  string|null  $lang  The Locale it fell back from.
     */
    public function __construct(private ?array $content = null, private ?string $lang = null) {}

    /**
     * Parses HTML and normalises it against the Field, as `SaveEntry` would: bare text in a list item or a quote is wrapped, anything else not allowed is unwrapped with its text kept.
     *
     * It reads `<pre><code class="language-x">`, `kami://` links and `<kami-block handle="..." data='{json}'>` markers.
     *
     * @throws InvalidArgumentException HTML that is not valid UTF-8, or a `<kami-block>` with no handle or data that is no JSON object.
     */
    public static function fromHtml(string $html, RichText $field): self
    {
        // tiptap-php's parser throws a `TypeError` on it.
        if (! mb_check_encoding($html, 'UTF-8')) {
            throw new InvalidArgumentException('HTML that is not valid UTF-8.');
        }

        $parsed = new Editor(['extensions' => [...self::extensions(new Link(['allowedProtocols' => ['http', 'https', 'mailto', 'tel', 'kami']])), ...RichTextHtml::nodes()]])
            ->setContent($html)
            ->getDocument();
        $normalised = $field->normalise($parsed);

        return new self(is_array($normalised) ? $normalised : null);
    }

    /** @internal */
    public function inLanguage(string $lang): self
    {
        return new self($this->content, $lang);
    }

    /**
     * @internal
     *
     * @return array<mixed>|null
     */
    public function content(): ?array
    {
        return $this->content;
    }

    /** The HTML, in an element carrying `lang` when the value fell back from another Locale. */
    public function toHtml(): string
    {
        if ($this->isEmpty()) {
            return '';
        }

        $html = new Editor(['extensions' => self::extensions(new Link(['HTMLAttributes' => ['target' => null, 'rel' => null]]))])->setContent($this->renderable($this->content ?? []))->getHTML();

        return $this->lang === null ? $html : '<div lang="' . e($this->lang) . '">' . $html . '</div>';
    }

    /** Its words, from text nodes only: Blocks are skipped. */
    public function text(): string
    {
        return Str::squish($this->textOf($this->content['content'] ?? []));
    }

    /** The start of its text, cut at a word boundary. */
    public function excerpt(int $chars = 160): string
    {
        return Str::limit($this->text(), $chars, '…', preserveWords: true);
    }

    /** Whether it holds no text and no Block. */
    public function isEmpty(): bool
    {
        return $this->text() === '' && $this->blocks() === [];
    }

    /**
     * Its Blocks in order, or those of one handle.
     *
     * @return list<array{handle: string, data: array<mixed>}>
     */
    public function blocks(?string $handle = null): array
    {
        $blocks = $this->blocksIn($this->content['content'] ?? []);

        return $handle === null ? $blocks : array_values(array_filter($blocks, fn (array $block): bool => $block['handle'] === $handle));
    }

    /** @return list<Extension> */
    private static function extensions(Link $link): array
    {
        return [
            new RootNode, new Text, new Paragraph, new Heading, new BulletList, new OrderedList, new ListItem,
            new Blockquote, new HorizontalRule, new HardBreak, new CodeBlock,
            new Bold, new Italic, new Code, $link,
        ];
    }

    /**
     * ponytail: a `kami://` link renders as its text and a Block as nothing; M10 renders both.
     *
     * @param  array<mixed>  $node
     * @return array<mixed>
     */
    private function renderable(array $node): array
    {
        if (isset($node['marks']) && is_array($node['marks'])) {
            $node['marks'] = array_values(array_filter($node['marks'], fn (mixed $mark): bool => ! is_string($href = data_get($mark, 'attrs.href')) || ! str_starts_with($href, 'kami://')));
        }

        if (isset($node['content']) && is_array($node['content'])) {
            $node['content'] = array_map(fn (mixed $child): mixed => is_array($child) ? $this->renderable($child) : $child, array_values(array_filter($node['content'], fn (mixed $child): bool => data_get($child, 'type') !== 'block')));
        }

        return $node;
    }

    private function textOf(mixed $nodes): string
    {
        $text = '';

        foreach (is_array($nodes) ? $nodes : [] as $node) {
            $own = data_get($node, 'text');
            $text .= match (data_get($node, 'type')) {
                'text' => is_string($own) ? $own : '',
                'hardBreak' => ' ',
                default => ' ' . $this->textOf(data_get($node, 'content')) . ' ',
            };
        }

        return $text;
    }

    /** @return list<array{handle: string, data: array<mixed>}> */
    private function blocksIn(mixed $nodes): array
    {
        $blocks = [];

        foreach (is_array($nodes) ? $nodes : [] as $node) {
            if (data_get($node, 'type') !== 'block') {
                array_push($blocks, ...$this->blocksIn(data_get($node, 'content')));
            } elseif (is_string($handle = data_get($node, 'attrs.handle')) && is_array($data = data_get($node, 'attrs.data'))) {
                $blocks[] = ['handle' => $handle, 'data' => $data];
            }
        }

        return $blocks;
    }
}
