<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal;

use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\Actions\LinkAction;
use Filament\Forms\Components\Select as SelectInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Psyao\Kami\Admin\Internal\Blocks\BlockSlot;
use Psyao\Kami\Content\Blocks\Block;
use Psyao\Kami\Content\Fields\Media as MediaField;
use Psyao\Kami\Content\Fields\RichText;
use Psyao\Kami\Content\RichText\Document;

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
            // Filament's link form with Kami's pickers and without its new-tab box, as `target` is never stored; its action still reads that value.
            ->registerActions([LinkAction::make()
                ->fillForm(fn (array $arguments): array => [...self::linkState($arguments['url'] ?? null), 'shouldOpenInNewTab' => false])
                ->schema([...self::linkInputs(), Hidden::make('shouldOpenInNewTab')->default(false)])
                ->mutateDataUsing(fn (array $data): array => [...$data, 'url' => self::linkHref($data)])])
            // Beyond what the toolbar offers, TipTap would blank these hrefs in the editor.
            ->linkProtocols(['http', 'https', 'mailto', 'tel', 'kami'])
            ->toolbarButtons(array_values(array_filter([
                ['bold', 'italic', 'link', ...($field->allowsCode() ? ['code'] : [])],
                array_map(fn (int $level): string => "h{$level}", $field->headingLevels()),
                ['blockquote', ...($field->allowsCode() ? ['codeBlock'] : []), 'bulletList', 'orderedList', 'horizontalRule'],
                ['undo', 'redo'],
            ])))
            // One button per offered Block, each through its slot (ADR 0024).
            ->customBlocks(BlockSlot::of($field->availableBlocks()))
            // After Filament's state cast, which removes a label.
            ->afterStateHydrated(fn (RichEditor $component, mixed $state): RichEditor => $component->rawState(is_array($state) ? self::labelled($state) : $state))
            ->dehydrateStateUsing(fn (mixed $state): mixed => is_array($state) ? self::fromEditor($state) : $state);
    }

    /**
     * A link's target: an Entry of a type with pages, Drafts included, or a Media item, each picked and stored as `kami://`, or an address as typed (ADR 0016).
     * `linkState()` fills them from an href, `linkHref()` reads one back.
     *
     * @return list<Component>
     */
    public static function linkInputs(): array
    {
        return [
            SelectInput::make('entry')
                ->label(__('kami::admin.rich_text.link_entry'))
                ->searchable()
                ->getSearchResultsUsing(fn (string $search, mixed $livewire): array => EntryResource::linkableTitles($search, $livewire))
                ->getOptionLabelUsing(fn (string $value, mixed $livewire): ?string => EntryResource::pickerTitles([$value], $livewire)[$value] ?? null),
            MediaResource::picker(MediaField::make('media'))->label(__('kami::admin.rich_text.link_media')),
            TextInput::make('url')->label(__('filament-forms::components.rich_editor.actions.link.modal.form.url.label'))->inputMode('url'),
        ];
    }

    /** @return array{entry: string|null, media: string|null, url: string|null} */
    public static function linkState(mixed $href): array
    {
        $target = is_string($href) ? Document::target($href) : null;

        return [
            'entry' => $target !== null && $target[0] === 'entries' ? $target[1] : null,
            'media' => $target !== null && $target[0] === 'media' ? $target[1] : null,
            'url' => $target === null && is_string($href) ? $href : null,
        ];
    }

    /**
     * The picked Entry's `kami://` href, else the picked Media item's, else the address typed.
     *
     * @param  array<mixed>  $data
     */
    public static function linkHref(array $data): ?string
    {
        return match (true) {
            is_string($data['entry'] ?? null) && $data['entry'] !== '' => "kami://entry/{$data['entry']}",
            is_string($data['media'] ?? null) && $data['media'] !== '' => "kami://media/{$data['media']}",
            default => is_string($data['url'] ?? null) ? $data['url'] : null,
        };
    }

    /**
     * The state a stored document fills the form with.
     *
     * @return array<mixed>|null
     */
    public static function fill(Document $document): ?array
    {
        return $document->isEmpty() ? null : self::toEditor($document->content() ?? [], $document->field());
    }

    /**
     * The editor's schema has no `block` and TipTap blanks a whole document it cannot read, so a Block travels as Filament's `customBlock`:
     * an offered one with its handle as `id`, which names its slot, and its data as `config`; an unavailable one with the whole Block in `id`,
     * which names no slot, and no `config`, so Filament offers delete and no edit (ADR 0016).
     *
     * @param  array<mixed>  $node
     * @return array<mixed>
     */
    public static function toEditor(array $node, ?RichText $field = null): array
    {
        if (($node['type'] ?? null) === 'block') {
            $handle = data_get($node, 'attrs.handle');
            $data = data_get($node, 'attrs.data');

            $block = is_string($handle) ? $field?->offered($handle) : null;

            return $block instanceof Block
                ? ['type' => 'customBlock', 'attrs' => ['id' => $block->handle(), 'config' => self::editorData($block, is_array($data) ? $data : [])]]
                : ['type' => 'customBlock', 'attrs' => ['id' => json_encode($node['attrs'] ?? [], JSON_THROW_ON_ERROR)]];
        }

        return self::mapContent($node, fn (array $child): array => self::toEditor($child, $field));
    }

    /**
     * A Block's data as its modal's inputs hold it: a single Media Field's picker one id, not the stored list.
     *
     * @param  array<mixed>  $data
     * @return array<string, mixed>
     */
    private static function editorData(Block $block, array $data): array
    {
        $editor = $block->normalise($data);

        foreach ($block->fields() as $field) {
            if ($field instanceof MediaField && ! $field->isMultiple() && is_array($editor[$field->handle])) {
                $editor[$field->handle] = $editor[$field->handle][0] ?? null;
            }
        }

        return $editor;
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
     * What an editor is reminded of on save, in document order and once each: a skipped heading level, counted from the Field's highest one as the title is H1, an empty heading, a link with no text.
     *
     * @return list<string>
     */
    public static function reminders(RichText $field, Document $document): array
    {
        $reminders = [];
        $level = min($field->headingLevels() ?: [2]) - 1;

        $walk = function (mixed $nodes) use (&$walk, &$reminders, &$level): void {
            foreach (is_array($nodes) ? $nodes : [] as $node) {
                if (data_get($node, 'type') === 'heading') {
                    $to = data_get($node, 'attrs.level');
                    $to = is_int($to) ? $to : 0;

                    if (trim(self::text(data_get($node, 'content'))) === '') {
                        $reminders[] = __('kami::admin.reminders.empty_heading');
                    }

                    if ($to > $level + 1) {
                        $reminders[] = __('kami::admin.reminders.skipped_level', ['from' => $level, 'to' => $to]);
                    }

                    $level = $to;
                }

                if (self::hasEmptyLink(data_get($node, 'content'))) {
                    $reminders[] = __('kami::admin.reminders.empty_link');
                }

                $walk(data_get($node, 'content'));
            }
        };
        $walk($document->content()['content'] ?? []);

        return array_values(array_unique(array_map(strval(...), $reminders)));
    }

    /** Whether a run of text nodes linking to one href holds only spaces: a link spans its nodes, whatever their other marks. */
    private static function hasEmptyLink(mixed $inline): bool
    {
        $href = null;
        $text = '';

        foreach ([...(is_array($inline) ? $inline : []), null] as $node) {
            $marks = data_get($node, 'marks');
            $next = data_get(collect(is_array($marks) ? $marks : [])->firstWhere('type', 'link'), 'attrs.href');

            if ($next !== $href && $href !== null && trim($text) === '') {
                return true;
            }

            $text = $next === $href ? $text . self::text([$node]) : self::text([$node]);
            $href = $next;
        }

        return false;
    }

    private static function text(mixed $nodes): string
    {
        return implode('', array_map(fn (mixed $node): string => is_string($text = data_get($node, 'text')) ? $text : '', is_array($nodes) ? $nodes : []));
    }

    /**
     * An unavailable Block's label, which no slot gives; Filament asks an offered one's slot for its own.
     *
     * @param  array<mixed>  $node
     * @return array<mixed>
     */
    private static function labelled(array $node): array
    {
        $whole = is_string($id = data_get($node, 'attrs.id')) ? json_decode($id, true) : null;

        if (($node['type'] ?? null) === 'customBlock' && is_array($whole)) {
            $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
            $handle = is_string($whole['handle'] ?? null) ? $whole['handle'] : '';

            return [...$node, 'attrs' => [...$attrs, 'label' => __('kami::admin.rich_text.unavailable_block', ['handle' => $handle])]];
        }

        return self::mapContent($node, self::labelled(...));
    }

    /**
     * The Block a `customBlock` holds: an unavailable one whole in its `id`, an offered one as its handle in `id` and its data in `config`. A handle is never JSON.
     *
     * @param  array<mixed>  $node
     * @return array<mixed>
     */
    private static function blockIn(array $node): array
    {
        $id = data_get($node, 'attrs.id');
        $block = is_string($id) ? json_decode($id, true) : null;

        if (is_array($block)) {
            return $block;
        }

        $config = data_get($node, 'attrs.config');

        return is_string($id) ? ['handle' => $id, 'data' => is_array($config) ? $config : []] : [];
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
