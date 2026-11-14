<?php

declare(strict_types=1);

namespace Workbench\App\Content\Blocks;

use Psyao\Kami\Content\Blocks\Block;
use Psyao\Kami\Content\Fields\Media;
use Psyao\Kami\Content\Fields\Select;
use Psyao\Kami\Content\Fields\Text;

/** A highlighted note inside an Article's body, with a tone and an optional image. */
final class Callout extends Block
{
    public function handle(): string
    {
        return 'callout';
    }

    public function fields(): array
    {
        return [
            Text::make('text')->required(),
            Select::make('tone')->options(['info', 'warning']),
            Media::make('image')->images(),
        ];
    }

    public function view(): string
    {
        return 'kami.blocks.callout';
    }

    public function summary(array $data): ?string
    {
        return is_string($data['text'] ?? null) ? $data['text'] : null;
    }
}
