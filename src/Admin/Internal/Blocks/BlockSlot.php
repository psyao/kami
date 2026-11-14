<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin\Internal\Blocks;

use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Schemas\Components\Component;
use LogicException;
use Psyao\Kami\Admin\Internal\EntryResource;
use Psyao\Kami\Admin\Internal\MediaResource;
use Psyao\Kami\Content\Blocks\Block;
use Psyao\Kami\Content\Blocks\Image;
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\Internal\Blocks;
use Psyao\Kami\Media\Media;

/**
 * Filament's rich editor takes a custom block as a class whose methods are all static, one class per block: each slot serves the Block `KamiPlugin` gives it (ADR 0024).
 *
 * ponytail: 64 slots, so 64 Blocks per installation, a 65th a boot error; V1 generates the classes into `bootstrap/cache` on a change of `kami.blocks` if a site needs more (ADR 0024).
 *
 * @internal
 */
abstract class BlockSlot extends RichContentCustomBlock
{
    public const int SLOTS = 64;

    /** @var array<class-string<self>, Block> */
    private static array $blocks = [];

    /**
     * Gives each Block a slot, in order: stored nodes name a Block's handle, never its slot, so slots may change between deploys.
     *
     * @param  list<Block>  $blocks
     */
    final public static function assign(array $blocks): void
    {
        if (count($blocks) > self::SLOTS) {
            throw new LogicException('kami.blocks lists ' . count($blocks) . ' Blocks; the admin\'s editor reaches ' . self::SLOTS . ' at most (ADR 0024).');
        }

        /** @var list<class-string<self>> $slots */
        $slots = array_map(fn (int $index): string => __NAMESPACE__ . '\Slot' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT), array_keys($blocks));
        self::$blocks = array_combine($slots, $blocks);
    }

    /**
     * The slots serving these Blocks, given out again from `kami.blocks` as the editor is built: a panel's every request builds it, its Livewire updates too.
     *
     * @param  list<Block>  $blocks
     * @return list<class-string<self>>
     */
    final public static function of(array $blocks): array
    {
        self::assign(array_values(resolve(Blocks::class)->all()));

        return array_values(array_filter(array_keys(self::$blocks), fn (string $slot): bool => in_array(self::$blocks[$slot], $blocks, true)));
    }

    #[\Override]
    final public static function getId(): string
    {
        return self::block()->handle();
    }

    #[\Override]
    final public static function getLabel(): string
    {
        return self::block()->label();
    }

    /** Its summary under its label, after Kami's `Image`'s thumbnail, escaped here: Filament trusts the preview's HTML (ADR 0016). */
    #[\Override]
    final public static function toPreviewHtml(array $config): ?string
    {
        $block = self::block();
        $data = $block->normalise($config);
        $summary = $block->summary($data);
        $html = ($block instanceof Image ? self::thumbnail($data['image']) : '') . ($summary === null || $summary === '' ? '' : '<p>' . e($summary) . '</p>');

        return $html === '' ? null : $html;
    }

    /** Its modal, built from its Fields as the Entry form builds a type's; none for a Block with no Field. */
    #[\Override]
    final public static function configureEditorAction(Action $action): Action
    {
        $block = self::block();

        return $block->fields() === [] ? $action->modalHidden() : $action->schema(array_map(fn (Field $field): Component => EntryResource::blockInput($block, $field, $action->getLivewire()), $block->fields()));
    }

    /** A picked image's `admin-thumb`, with its alt text; none once it is gone. */
    private static function thumbnail(mixed $ids): string
    {
        $media = is_array($ids) && is_string($ids[0] ?? null) ? Media::query()->find($ids[0]) : null;

        if (! $media instanceof Media || ($url = MediaResource::preview($media)) === null) {
            return '';
        }

        return '<img src="' . e($url) . '" alt="' . e((string) $media->alt()) . '">';
    }

    private static function block(): Block
    {
        return self::$blocks[static::class] ?? throw new LogicException(static::class . ' serves no Block.');
    }
}
