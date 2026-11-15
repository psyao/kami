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
use Psyao\Kami\Routing\Internal\VisiblePaths;
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
     * @param  string|null  $locale  The Locale its Entry is read in, where its links and its Blocks' Entries are read; the current Locale when null.
     */
    public function __construct(private ?array $content = null, private ?string $lang = null, private ?RichText $field = null, private ?string $locale = null) {}

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

        // Nothing to read, as a blank paragraph is; tiptap-php's parser finds no body in it and throws a `TypeError`.
        if (trim($html) === '') {
            return new self(field: $field);
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
        return new self($this->content, $lang, $this->field, $this->locale);
    }

    /** @internal */
    public function readIn(string $locale): self
    {
        return new self($this->content, $this->lang, $this->field, $locale);
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

        [$media, $entries] = $this->read();
        $html = new Editor(['extensions' => [...self::extensions(new Link(['HTMLAttributes' => ['target' => null, 'rel' => null]])), $this->blockNode($media, $entries)]])
            ->setContent($this->renderable($this->content ?? [], $media, $entries))
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

    /**
     * @internal
     *
     * The ids its `kami://` links name, in document order, once each.
     *
     * @return array{entries: list<string>, media: list<string>}
     */
    public function links(): array
    {
        $links = ['entries' => [], 'media' => []];

        foreach ($this->hrefsIn($this->content['content'] ?? []) as $href) {
            if (($target = self::target($href)) !== null) {
                $links[$target[0]][] = $target[1];
            }
        }

        return ['entries' => array_values(array_unique($links['entries'])), 'media' => array_values(array_unique($links['media']))];
    }

    /**
     * What a `kami://` href names: `entries` or `media`, and an id; `null` for any other href (ADR 0016).
     *
     * @internal
     *
     * @return array{0: 'entries'|'media', 1: string}|null
     */
    public static function target(string $href): ?array
    {
        return preg_match('~^kami://(entry|media)/([0-9A-Za-z]+)$~', $href, $match) === 1 ? [$match[1] === 'entry' ? 'entries' : 'media', $match[2]] : null;
    }

    /**
     * The `kami://` href `target()` reads back.
     *
     * @internal
     *
     * @param  'entries'|'media'  $kind
     */
    public static function href(string $kind, string $id): string
    {
        return 'kami://' . ($kind === 'entries' ? 'entry' : 'media') . "/{$id}";
    }

    /**
     * @internal
     *
     * A document with the `kami://` links to these targets reduced to their text, and any naming no target; a Block's own RichText's too.
     *
     * @param  array<mixed>  $node
     * @param  list<string>  $entries
     * @param  list<string>  $media
     * @return array<mixed>
     */
    public static function withoutLinks(array $node, array $entries, array $media): array
    {
        if (is_array($node['marks'] ?? null)) {
            $node['marks'] = array_values(array_filter($node['marks'], function (mixed $mark) use ($entries, $media): bool {
                if (! is_string($href = data_get($mark, 'attrs.href')) || ! str_starts_with($href, 'kami://')) {
                    return true;
                }

                $target = self::target($href);

                return $target !== null && ! in_array($target[1], $target[0] === 'entries' ? $entries : $media, true);
            }));

            if ($node['marks'] === []) {
                unset($node['marks']);
            }
        }

        // A Block's data holds its RichText Fields' documents.
        if (($node['type'] ?? null) === 'block' && is_array($attrs = $node['attrs'] ?? null) && is_array($attrs['data'] ?? null)) {
            $node['attrs'] = [...$attrs, 'data' => array_map(fn (mixed $value): mixed => is_array($value) && ($value['type'] ?? null) === 'doc' ? self::withoutLinks($value, $entries, $media) : $value, $attrs['data'])];
        }

        if (is_array($node['content'] ?? null)) {
            $node['content'] = array_map(fn (mixed $child): mixed => is_array($child) ? self::withoutLinks($child, $entries, $media) : $child, $node['content']);
        }

        return $node;
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
     * A `kami://` link at its target's URL: an Entry's in the read Locale, a Media item's own; a target not read, hidden or gone, as its text.
     *
     * @param  array<mixed>  $node
     * @param  array<string, Media>  $media
     * @param  array<string, Entry>  $entries
     * @return array<mixed>
     */
    private function renderable(array $node, array $media, array $entries): array
    {
        if (isset($node['marks']) && is_array($node['marks'])) {
            $marks = [];

            foreach ($node['marks'] as $mark) {
                if (is_string($href = data_get($mark, 'attrs.href')) && str_starts_with($href, 'kami://')) {
                    $target = self::target($href);
                    $url = match ($target[0] ?? null) {
                        'entries' => ($entries[$target[1]] ?? null)?->url($this->readLocale()),
                        'media' => ($media[$target[1]] ?? null)?->url(),
                        default => null,
                    };

                    if ($url === null) {
                        continue;
                    }

                    data_set($mark, 'attrs.href', $url);
                }

                $marks[] = $mark;
            }

            $node['marks'] = $marks;
        }

        if (isset($node['content']) && is_array($node['content'])) {
            $node['content'] = array_map(fn (mixed $child): mixed => is_array($child) ? $this->renderable($child, $media, $entries) : $child, $node['content']);
        }

        return $node;
    }

    /**
     * The `block` node, each rendered through its Block's view; an unavailable one renders nothing (ADR 0016).
     *
     * @param  array<string, Media>  $media
     * @param  array<string, Entry>  $entries
     */
    private function blockNode(array $media, array $entries): Node
    {
        return new class($this->blockRenderer($media, $entries)) extends Node
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
     * The Media items and visible Entries its Blocks and links name, read in one batch: Media items and their Translations, two queries;
     * Entries with their Translations and type rows, three, then the Paths of those nested; none for no id.
     *
     * @return array{0: array<string, Media>, 1: array<string, Entry>}
     */
    private function read(): array
    {
        $ids = $this->field?->targets($this->content) ?? $this->links();
        $site = Site::current()->handle;
        $entries = [];

        // Another Site's target, which no picker offers, reads as gone.
        foreach ($ids['entries'] === [] ? [] : Entry::query()->readIn($this->readLocale())->where('site', $site)->whereIn('id', $ids['entries'])->get() as $entry) {
            $entries[$entry->id] = $entry;
        }

        VisiblePaths::preload($entries);

        return [array_filter(References::media($ids['media']), fn (Media $item): bool => $item->site === $site), $entries];
    }

    private function readLocale(): string
    {
        return $this->locale ?? Site::current()->currentLocale();
    }

    /**
     * What renders a Block, from the Media items and Entries read beforehand.
     *
     * @param  array<string, Media>  $media
     * @param  array<string, Entry>  $entries
     * @return Closure(string, array<mixed>): string
     */
    private function blockRenderer(array $media, array $entries): Closure
    {
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
            $read = $field->read($value);

            return $read instanceof self ? $read->readIn($this->readLocale()) : $read;
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

    /** @return list<string> */
    private function hrefsIn(mixed $nodes): array
    {
        $hrefs = [];

        foreach (is_array($nodes) ? $nodes : [] as $node) {
            foreach ((array) data_get($node, 'marks', []) as $mark) {
                if (data_get($mark, 'type') === 'link' && is_string($href = data_get($mark, 'attrs.href'))) {
                    $hrefs[] = $href;
                }
            }

            array_push($hrefs, ...$this->hrefsIn(data_get($node, 'content')));
        }

        return $hrefs;
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
