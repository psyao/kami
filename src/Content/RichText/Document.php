<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\RichText;

use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Psyao\Kami\Content\Blocks\Block;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\Fields\Media as MediaField;
use Psyao\Kami\Content\Fields\RichText;
use Psyao\Kami\Content\Internal\References;
use Psyao\Kami\Content\Internal\RichTextHtml;
use Psyao\Kami\Media\Media;
use Psyao\Kami\Sites\Internal\Site;
use Tiptap\Core\Extension;
use Tiptap\Core\Node;
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
     * @param  RichText|null  $field  The Field it is read from, which says what its Blocks are; none renders no Block.
     */
    public function __construct(private ?array $content = null, private ?string $lang = null, private ?RichText $field = null) {}

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
            throw new InvalidArgumentException('Unreadable HTML: not valid UTF-8.');
        }

        $parsed = new Editor(['extensions' => [...self::extensions(new Link(['allowedProtocols' => ['http', 'https', 'mailto', 'tel', 'kami']])), ...RichTextHtml::nodes()]])
            ->setContent($html)
            ->getDocument();
        $normalised = $field->normalise($parsed);

        return new self(is_array($normalised) ? $normalised : null, field: $field);
    }

    /** @internal */
    public function inLanguage(string $lang): self
    {
        return new self($this->content, $lang, $this->field);
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

    /** @internal */
    public function field(): ?RichText
    {
        return $this->field;
    }

    /** The HTML, in an element carrying `lang` when the value fell back from another Locale. */
    public function toHtml(): string
    {
        if ($this->isEmpty()) {
            return '';
        }

        $html = new Editor(['extensions' => [...self::extensions(new Link(['HTMLAttributes' => ['target' => null, 'rel' => null]])), $this->blockNode()]])
            ->setContent($this->renderable($this->content ?? []))
            ->getHTML();

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
     * ponytail: a `kami://` link renders as its text; #627 renders it at its target's URL.
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
            $node['content'] = array_map(fn (mixed $child): mixed => is_array($child) ? $this->renderable($child) : $child, $node['content']);
        }

        return $node;
    }

    /** The `block` node, each rendered through its Block's view; an unavailable one renders nothing (ADR 0016). */
    private function blockNode(): Node
    {
        return new class($this->blockRenderer()) extends Node
        {
            /** @var string */
            public static $name = 'block';

            /** @param  Closure(string, array<mixed>): string  $render */
            public function __construct(private readonly Closure $render)
            {
                parent::__construct();
            }

            /**
             * @param  object  $node
             * @return array{content: string}
             */
            #[\Override]
            public function renderHTML($node): array
            {
                $handle = data_get($node, 'attrs.handle');
                // tiptap-php hands its nodes over as objects.
                $data = json_decode(json_encode(data_get($node, 'attrs.data', []), JSON_THROW_ON_ERROR), true);

                return ['content' => ($this->render)(is_string($handle) ? $handle : '', is_array($data) ? $data : [])];
            }
        };
    }

    /**
     * What renders a Block, with the Media items and visible Entries its Blocks link read beforehand: one query each, with their Translations, none for no id.
     *
     * @return Closure(string, array<mixed>): string
     */
    private function blockRenderer(): Closure
    {
        $ids = $this->field?->targets($this->content) ?? ['entries' => [], 'media' => []];
        $media = References::media($ids['media']);
        $entries = [];

        foreach ($ids['entries'] === [] ? [] : Entry::query()->readIn(Site::current()->currentLocale())->whereIn('id', $ids['entries'])->get() as $entry) {
            $entries[$entry->id] = $entry;
        }

        return function (string $handle, array $data) use ($media, $entries): string {
            $block = $this->field?->offered($handle);

            if (! $block instanceof Block) {
                return '';
            }

            $typed = [];

            foreach ($block->fields() as $field) {
                $typed[$field->handle] = $this->typed($field, $data[$field->handle] ?? null, $media, $entries);
            }

            return view()->make($block->view(), $typed)->render();
        };
    }

    /**
     * A Block's value as an Entry reads its Field's: Media items and Entries from those read beforehand, in pick order, a missing one left out.
     *
     * @param  array<string, Media>  $media
     * @param  array<string, Entry>  $entries
     */
    private function typed(Field $field, mixed $value, array $media, array $entries): mixed
    {
        if (! $field->isReference()) {
            return $field->read($value);
        }

        $read = $field instanceof MediaField ? $media : $entries;
        $found = new Collection(array_values(array_filter(array_map(fn (mixed $id): mixed => is_string($id) ? $read[$id] ?? null : null, is_array($value) ? $value : []))));

        return $field instanceof MediaField && ! $field->isMultiple() ? $found->first() : $found;
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
