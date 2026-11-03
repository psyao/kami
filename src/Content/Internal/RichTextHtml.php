<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use DOMElement;
use InvalidArgumentException;
use Tiptap\Core\Node;

/**
 * What `Document::fromHtml()` reads beyond the rendered set: `<kami-block>` markers, and block elements no node knows, kept apart so their words stay apart (ADR 0016).
 *
 * @internal
 */
final class RichTextHtml
{
    /** @return list<Node> */
    public static function nodes(): array
    {
        return [
            new class extends Node
            {
                /** @var string */
                public static $name = 'block';

                /** @return list<array<string, mixed>> */
                public function parseHTML(): array
                {
                    return [['tag' => 'kami-block', 'getAttrs' => RichTextHtml::block(...)]];
                }
            },
            // The normaliser unwraps this unknown type: its inline content becomes a paragraph of its own.
            new class extends Node
            {
                /** @var string */
                public static $name = 'unknownBlock';

                /** @return list<array<string, mixed>> */
                public function parseHTML(): array
                {
                    return array_map(fn (string $tag): array => ['tag' => $tag], [
                        'address', 'article', 'aside', 'caption', 'dd', 'details', 'div', 'dl', 'dt', 'fieldset', 'figcaption', 'figure', 'footer',
                        'header', 'legend', 'main', 'nav', 'section', 'summary', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr',
                    ]);
                }
            },
        ];
    }

    /**
     * A marker is written by an importer: one it cannot read is a bug there, not content to drop.
     *
     * @return array{handle: string, data: array<mixed>}
     */
    public static function block(DOMElement $marker): array
    {
        $handle = $marker->getAttribute('handle');
        $data = json_decode($marker->getAttribute('data') ?: '{}', true);

        if ($handle === '' || ! is_array($data)) {
            throw new InvalidArgumentException('Unreadable <kami-block> marker: ' . $marker->ownerDocument?->saveHTML($marker));
        }

        return ['handle' => $handle, 'data' => $data];
    }
}
