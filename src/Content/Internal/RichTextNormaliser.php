<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

/**
 * Rebuilds a rich text document from what a Field allows, so forged editor state, an import and a site's code end in the same clean document (ADR 0016).
 *
 * The result does not change when normalised again, nor when the editor hands it back: marks in one order, neighbours with the same marks joined, as ProseMirror does.
 *
 * @internal
 */
final readonly class RichTextNormaliser
{
    private const array LINK_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /** @param  list<int>  $levels */
    public function __construct(private bool $code, private array $levels) {}

    /**
     * @param  array<mixed>  $document
     * @return array<mixed>
     */
    public function document(array $document): array
    {
        return ['type' => 'doc', 'content' => $this->nodes($document['content'] ?? [])];
    }

    /** @return list<array<mixed>> */
    private function nodes(mixed $content): array
    {
        $nodes = $inline = [];

        foreach (is_array($content) ? $content : [] as $node) {
            if (! is_array($node)) {
                continue;
            }

            if (in_array($node['type'] ?? null, ['text', 'hardBreak'], true)) {
                $inline[] = $node;

                continue;
            }

            array_push($nodes, ...$this->wrapped($inline), ...$this->node($node));
            $inline = [];
        }

        return [...$nodes, ...$this->wrapped($inline)];
    }

    /**
     * Bare text and breaks as a paragraph; nothing when they hold nothing.
     *
     * @param  list<array<mixed>>  $nodes
     * @return list<array<mixed>>
     */
    private function wrapped(array $nodes): array
    {
        $inline = $this->inline($nodes);

        return $inline === [] ? [] : [['type' => 'paragraph', 'content' => $inline]];
    }

    /**
     * What one node becomes: an unknown one is unwrapped, keeping its text.
     *
     * @param  array<mixed>  $node
     * @return list<array<mixed>>
     */
    private function node(array $node): array
    {
        $content = $node['content'] ?? [];

        return match ($node['type'] ?? null) {
            'paragraph' => [$this->withContent(['type' => 'paragraph'], $this->inline($content)), ...$this->liftedBlocks($content)],
            'heading' => [$this->withContent(['type' => 'heading', 'attrs' => ['level' => $this->level(data_get($node, 'attrs.level'))]], $this->inline($content)), ...$this->liftedBlocks($content)],
            'bulletList', 'orderedList' => $this->listOf((string) $node['type'], $content),
            'blockquote' => ($quoted = $this->nodes($content)) === [] ? [] : [['type' => 'blockquote', 'content' => $quoted]],
            'horizontalRule' => [['type' => 'horizontalRule']],
            'codeBlock' => $this->codeBlock($node),
            'block' => $this->blockNode($node),
            default => $this->nodes($content),
        };
    }

    /**
     * A Block inside text moves after it: inline, it would be lost (ADR 0016).
     *
     * @return list<array<mixed>>
     */
    private function liftedBlocks(mixed $content): array
    {
        $blocks = [];

        foreach (is_array($content) ? $content : [] as $node) {
            if (is_array($node)) {
                array_push($blocks, ...(($node['type'] ?? null) === 'block' ? $this->blockNode($node) : $this->liftedBlocks($node['content'] ?? [])));
            }
        }

        return $blocks;
    }

    /**
     * @param  array<mixed>  $node
     * @param  list<array<mixed>>  $content
     * @return array<mixed>
     */
    private function withContent(array $node, array $content): array
    {
        return $content === [] ? $node : [...$node, 'content' => $content];
    }

    /** The nearest allowed level, the highest one when it names none. */
    private function level(mixed $level): int
    {
        $wanted = is_int($level) ? $level : 0;
        $levels = $this->levels;
        sort($levels);
        usort($levels, fn (int $a, int $b): int => abs($a - $wanted) <=> abs($b - $wanted));

        return $levels[0];
    }

    /** @return list<array<mixed>> */
    private function listOf(string $type, mixed $content): array
    {
        $items = [];

        foreach (is_array($content) ? $content : [] as $child) {
            if (is_array($child)) {
                $held = ($child['type'] ?? null) === 'listItem' ? $child['content'] ?? [] : [$child];
                $items[] = ['type' => 'listItem', 'content' => $this->nodes($held) ?: [['type' => 'paragraph']]];
            }
        }

        return $items === [] ? [] : [['type' => $type, 'content' => $items]];
    }

    /**
     * A code block holds unmarked text; without `->code()`, its text stays as a paragraph.
     *
     * @param  array<mixed>  $node
     * @return list<array<mixed>>
     */
    private function codeBlock(array $node): array
    {
        $text = $this->plainText($node['content'] ?? []);
        $content = $text === '' ? [] : [['type' => 'text', 'text' => $text]];

        if (! $this->code) {
            return $this->wrapped($content);
        }

        $language = data_get($node, 'attrs.language');
        $safe = is_string($language) && preg_match('/^[\w+#.-]+$/', $language) === 1;

        return [$this->withContent(['type' => 'codeBlock', ...($safe ? ['attrs' => ['language' => $language]] : [])], $content)];
    }

    private function plainText(mixed $nodes): string
    {
        $text = '';

        foreach (is_array($nodes) ? $nodes : [] as $node) {
            if (is_array($node)) {
                $text .= match (true) {
                    ($node['type'] ?? null) === 'hardBreak' => "\n",
                    ($node['type'] ?? null) === 'text' && is_string($node['text'] ?? null) => $node['text'],
                    default => $this->plainText($node['content'] ?? []),
                };
            }
        }

        return $text;
    }

    /**
     * ponytail: any handle and data pass, kept as they are (ADR 0016); M10 validates a Block's Fields.
     *
     * @param  array<mixed>  $node
     * @return list<array<mixed>>
     */
    private function blockNode(array $node): array
    {
        $handle = data_get($node, 'attrs.handle');
        $data = data_get($node, 'attrs.data', []);

        return is_string($handle) && $handle !== '' && is_array($data) ? [['type' => 'block', 'attrs' => ['handle' => $handle, 'data' => $data]]] : [];
    }

    /** @return list<array{type: string, text?: string, marks?: list<array<mixed>>}> */
    private function inline(mixed $content): array
    {
        $nodes = [];

        foreach (is_array($content) ? $content : [] as $node) {
            if (! is_array($node)) {
                continue;
            }

            $type = $node['type'] ?? null;

            if ($type === 'hardBreak') {
                $nodes[] = ['type' => 'hardBreak'];
            } elseif ($type !== 'text') {
                array_push($nodes, ...$this->inline($node['content'] ?? []));
            } elseif (is_string($node['text'] ?? null) && $node['text'] !== '') {
                $marks = $this->marks($node['marks'] ?? []);
                $nodes[] = ['type' => 'text', 'text' => $node['text'], ...($marks === [] ? [] : ['marks' => $marks])];
            }
        }

        return $this->joined($nodes);
    }

    /**
     * @param  list<array{type: string, text?: string, marks?: list<array<mixed>>}>  $nodes
     * @return list<array{type: string, text?: string, marks?: list<array<mixed>>}>
     */
    private function joined(array $nodes): array
    {
        $joined = [];

        foreach ($nodes as $node) {
            $last = array_key_last($joined);

            if ($last !== null && $node['type'] === 'text' && $joined[$last]['type'] === 'text' && ($joined[$last]['marks'] ?? []) === ($node['marks'] ?? [])) {
                $joined[$last]['text'] = ($joined[$last]['text'] ?? '') . ($node['text'] ?? '');
            } else {
                $joined[] = $node;
            }
        }

        return $joined;
    }

    /** @return list<array<mixed>> */
    private function marks(mixed $marks): array
    {
        $kept = [];

        foreach (is_array($marks) ? $marks : [] as $mark) {
            $type = is_array($mark) ? $mark['type'] ?? null : null;

            if ($type === 'link') {
                $href = data_get($mark, 'attrs.href');

                $safe = is_string($href) ? $this->safeHref($href) : null;

                if ($safe !== null) {
                    $kept['link'] = ['type' => 'link', 'attrs' => ['href' => $safe]];
                }
            } elseif ($type === 'bold' || $type === 'italic' || ($type === 'code' && $this->code)) {
                $kept[$type] = ['type' => $type];
            }
        }

        return array_values(array_filter(['bold' => $kept['bold'] ?? null, 'italic' => $kept['italic'] ?? null, 'code' => $kept['code'] ?? null, 'link' => $kept['link'] ?? null]));
    }

    /** Read as a browser reads it: spaces and control characters inside a scheme are ignored. A `kami://` link leaves in one spelling, the one its readers look for. */
    private function safeHref(string $href): ?string
    {
        $clean = (string) preg_replace('/[\x00-\x20\x{00A0}\x{1680}\x{180E}\x{2000}-\x{2029}\x{205F}\x{3000}]/u', '', $href);

        if ($clean === '') {
            return null;
        }

        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $clean, $scheme) !== 1) {
            return $href;
        }

        if (stripos($clean, 'kami://') === 0) {
            return 'kami://' . substr($clean, 7);
        }

        return in_array(strtolower($scheme[1]), self::LINK_SCHEMES, true) ? $href : null;
    }
}
