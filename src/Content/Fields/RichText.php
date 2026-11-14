<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Fields;

use Illuminate\Support\Str;
use Psyao\Kami\Content\Blocks\Block;
use Psyao\Kami\Content\Internal\Blocks;
use Psyao\Kami\Content\Internal\RichTextNormaliser;
use Psyao\Kami\Content\RichText\Document;

/** Formatted text and links, in one `json` column, read as a `Document` even when blank. `SaveEntry` normalises it against what this Field allows (ADR 0016). */
final class RichText extends Field
{
    private const array HEADING_LEVELS = [2, 3, 4];

    private bool $code = false;

    /** @var array<mixed> As declared: `ContentTypes` refuses any other level. */
    private array $headings = self::HEADING_LEVELS;

    /** @var list<class-string<Block>> */
    private array $blocks = [];

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

    /**
     * Offers these Blocks in the editor, each listed in `kami.blocks`. A Block it stops offering stays in its documents, unavailable: read-only in the editor, rendering nothing (ADR 0016).
     *
     * @param  list<class-string<Block>>  $blocks
     */
    public function blocks(array $blocks): static
    {
        $this->blocks = $blocks;

        return $this;
    }

    /**
     * @internal
     *
     * @return list<class-string<Block>>
     */
    public function offeredBlocks(): array
    {
        return $this->blocks;
    }

    /**
     * @internal
     *
     * The Block of this handle when it is declared and offered here; `null` for an unavailable one.
     */
    public function offered(string $handle): ?Block
    {
        return array_find($this->availableBlocks(), fn (Block $block): bool => $block->handle() === $handle);
    }

    /**
     * @internal
     *
     * The Blocks it offers that `kami.blocks` declares, in declaration order.
     *
     * @return list<Block>
     */
    public function availableBlocks(): array
    {
        return $this->blocks === [] ? [] : array_values(array_filter(resolve(Blocks::class)->all(), fn (Block $block): bool => in_array($block::class, $this->blocks, true)));
    }

    /**
     * @internal
     *
     * Its offered Blocks' rules at `{attribute}.blocks.{n}.{field}`, `n` counting every Block of the document, unavailable ones included,
     * which take none; with each Block's data at that path and the names errors give.
     *
     * @param  array<mixed>  $document
     * @return array{data: list<array<mixed>>, rules: array<string, array<mixed>>, names: array<string, string>}
     */
    public function blockValidation(string $attribute, array $document, bool $publishing): array
    {
        $data = $rules = $names = [];

        foreach (new Document($document)->blocks() as $n => $stored) {
            $data[] = $stored['data'];
            $block = $this->offered($stored['handle']);

            if (! $block instanceof Block) {
                continue;
            }

            foreach ($block->fields() as $field) {
                $at = "{$attribute}.blocks.{$n}.";

                foreach ($field->rulesAt($at . $field->handle, $publishing) as $key => $keyRules) {
                    $rules[$key] = $keyRules;
                    $names[$key] = (string) __('kami::admin.rich_text.block_attribute', [
                        'field' => $block->fieldLabel(str_replace(['.*', '.'], ['', '.fields.'], Str::after($key, $at))),
                        'block' => $block->label(),
                        'position' => $n + 1,
                    ]);
                }
            }
        }

        return ['data' => $data, 'rules' => $rules, 'names' => $names];
    }

    /**
     * @internal
     *
     * What its offered Blocks and its `kami://` links name, a Block's own RichText's links included, in document order, once each: the rows `SaveEntry` writes to `kami_references` under its handle.
     *
     * @param  array<mixed>|null  $document
     * @return array{entries: list<string>, media: list<string>}
     */
    public function targets(?array $document): array
    {
        $read = new Document($document);
        $targets = $read->links();

        foreach ($read->blocks() as $stored) {
            foreach ($this->offered($stored['handle'])?->fields() ?? [] as $field) {
                $ids = $stored['data'][$field->handle] ?? null;

                if ($field->isReference() && is_array($ids)) {
                    array_push($targets[$field instanceof Media ? 'media' : 'entries'], ...array_filter($ids, is_string(...)));
                } elseif ($field instanceof self && is_array($ids)) {
                    $nested = $field->targets($ids);
                    array_push($targets['entries'], ...$nested['entries']);
                    array_push($targets['media'], ...$nested['media']);
                }
            }
        }

        return ['entries' => array_values(array_unique($targets['entries'])), 'media' => array_values(array_unique($targets['media']))];
    }

    /**
     * @internal
     *
     * The document with these Entry ids gone from its offered Blocks' relation Fields: those trashed, read as absent, or deleted, dropped.
     *
     * @param  array<mixed>  $document
     * @param  list<string>  $ids
     * @return array<mixed>
     */
    public function withoutEntries(array $document, array $ids): array
    {
        if ($ids === []) {
            return $document;
        }

        if (($document['type'] ?? null) === 'block' && is_string($handle = data_get($document, 'attrs.handle')) && is_array($data = data_get($document, 'attrs.data'))) {
            foreach ($this->offered($handle)?->fields() ?? [] as $field) {
                if ($field instanceof RelationField && is_array($data[$field->handle] ?? null)) {
                    $data[$field->handle] = array_values(array_diff(array_filter($data[$field->handle], is_string(...)), $ids)) ?: null;
                }
            }

            return ['type' => 'block', 'attrs' => ['handle' => $handle, 'data' => $data]];
        }

        if (is_array($document['content'] ?? null)) {
            $document['content'] = array_map(fn (mixed $node): mixed => is_array($node) ? $this->withoutEntries($node, $ids) : $node, $document['content']);
        }

        return $document;
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
     * A document holding nothing is `null`, so `required()` and the Fallback read it as blank. A `Document`, from `Document::fromHtml()` for instance, is normalised as its content. One that is not valid UTF-8 stays as given, for `encodingRule()` to refuse.
     */
    #[\Override]
    public function normalise(mixed $value): mixed
    {
        if ($value instanceof Document) {
            $value = $value->content() ?? [];
        }

        // @phpstan-ignore argument.type (it walks nested arrays, keys included, and passes any other scalar)
        if (! is_array($value) || ! mb_check_encoding($value, 'UTF-8')) {
            return parent::normalise($value);
        }

        $document = new RichTextNormaliser($this->code, $this->headingLevels(), $this->offered(...))->document($value);

        return new Document($document)->isEmpty() ? null : $document;
    }

    /** @internal */
    #[\Override]
    public function read(mixed $stored): Document
    {
        return new Document($this->decoded($stored), field: $this);
    }

    /** @internal */
    #[\Override]
    protected function baseRules(): array
    {
        return ['array', self::encodingRule()];
    }

    /** @internal */
    #[\Override]
    protected function store(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
