<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Psyao\Kami\Content\Blocks\Block;

/**
 * The Blocks listed in `config/kami.php`, by handle, validated when first read, never at boot, as Content Types are (ADR 0007).
 *
 * @internal
 */
final class Blocks
{
    /** @var array<mixed>|null The `kami.blocks` the registry was built from. */
    private ?array $classes = null;

    /** @var array<string, Block> By handle. */
    private array $blocks = [];

    /** @return array<string, Block> By handle, in declaration order. */
    public function all(): array
    {
        // Rebuilt only when `kami.blocks` changes, as in a test.
        if (($classes = Config::array('kami.blocks', [])) === $this->classes) {
            return $this->blocks;
        }

        $blocks = [];
        $types = resolve(ContentTypes::class);

        foreach ($classes as $class) {
            $block = is_string($class) ? app()->make($class) : null;

            if (! $block instanceof Block) {
                throw new InvalidArgumentException('Every entry of kami.blocks is a class extending ' . Block::class . '.');
            }

            if (isset($blocks[$block->handle()])) {
                throw new InvalidArgumentException("{$class}: the handle \"{$block->handle()}\" is already the handle of " . $blocks[$block->handle()]::class . '; change handle() on one of them.');
            }

            $types->validateBlock($class, $block);
            $blocks[$block->handle()] = $block;
        }

        [$this->blocks, $this->classes] = [$blocks, $classes];

        return $blocks;
    }
}
