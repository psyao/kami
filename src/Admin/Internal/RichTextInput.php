<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal;

use Filament\Forms\Components\RichEditor;
use Psyao\Kami\Content\Fields\RichText;

/**
 * Filament's editor for a `RichText` Field, and the translation between Kami's document and its state (ADR 0016).
 *
 * @internal
 */
final class RichTextInput
{
    public static function make(RichText $field): RichEditor
    {
        return RichEditor::make($field->handle)
            ->json()
            ->fileAttachments(false)
            // Beyond what the toolbar offers, TipTap would blank these hrefs in the editor.
            ->linkProtocols(['http', 'https', 'mailto', 'tel', 'kami'])
            ->toolbarButtons(array_values(array_filter([
                ['bold', 'italic', 'link', ...($field->allowsCode() ? ['code'] : [])],
                array_map(fn (int $level): string => "h{$level}", $field->headingLevels()),
                ['blockquote', ...($field->allowsCode() ? ['codeBlock'] : []), 'bulletList', 'orderedList', 'horizontalRule'],
                ['undo', 'redo'],
            ])))
            // After Filament's state cast, which removes a label.
            ->afterStateHydrated(fn (RichEditor $component, mixed $state): RichEditor => $component->rawState(is_array($state) ? self::labelled($state) : $state))
            ->dehydrateStateUsing(fn (mixed $state): mixed => is_array($state) ? self::fromEditor($state) : $state);
    }

    /**
     * The editor's schema has no `block` and TipTap blanks a whole document it cannot read, so a Block travels as Filament's `customBlock`.
     *
     * ponytail: its `id` holds the Block and its `config` nothing, so Filament offers delete and no edit; M10 shows the Block's own label and summary.
     *
     * @param  array<mixed>  $node
     * @return array<mixed>
     */
    public static function toEditor(array $node): array
    {
        if (($node['type'] ?? null) === 'block') {
            return ['type' => 'customBlock', 'attrs' => ['id' => json_encode($node['attrs'] ?? [], JSON_THROW_ON_ERROR)]];
        }

        return self::mapContent($node, self::toEditor(...));
    }

    /**
     * What the editor holds, in Kami's names. `SaveEntry` normalises the rest.
     *
     * @param  array<mixed>  $node
     * @return array<mixed>
     */
    public static function fromEditor(array $node): array
    {
        if (($node['type'] ?? null) === 'customBlock') {
            return ['type' => 'block', 'attrs' => self::blockIn($node)];
        }

        return self::mapContent($node, self::fromEditor(...));
    }

    /**
     * @param  array<mixed>  $node
     * @return array<mixed>
     */
    private static function labelled(array $node): array
    {
        if (($node['type'] ?? null) === 'customBlock') {
            $handle = self::blockIn($node)['handle'] ?? null;
            $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];

            return [...$node, 'attrs' => [...$attrs, 'label' => __('kami::admin.rich_text.unavailable_block', ['handle' => is_string($handle) ? $handle : ''])]];
        }

        return self::mapContent($node, self::labelled(...));
    }

    /**
     * @param  array<mixed>  $node
     * @return array<mixed>
     */
    private static function blockIn(array $node): array
    {
        $id = data_get($node, 'attrs.id');
        $block = is_string($id) ? json_decode($id, true) : null;

        return is_array($block) ? $block : [];
    }

    /**
     * @param  array<mixed>  $node
     * @param  callable(array<mixed>): array<mixed>  $map
     * @return array<mixed>
     */
    private static function mapContent(array $node, callable $map): array
    {
        if (isset($node['content']) && is_array($node['content'])) {
            $node['content'] = array_map(fn (mixed $child): mixed => is_array($child) ? $map($child) : $child, $node['content']);
        }

        return $node;
    }
}
