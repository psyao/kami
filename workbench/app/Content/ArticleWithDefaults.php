<?php

declare(strict_types=1);

namespace Workbench\App\Content;

use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Fields\Text;

/** Article with default values, on Article's handle and table: tests list it in place of Article. */
final class ArticleWithDefaults extends ContentType
{
    #[\Override]
    protected ?string $prefix = 'articles';

    public function handle(): string
    {
        return 'article';
    }

    public function fields(): array
    {
        return [
            Text::make('summary')->default('To be written.'),
            Text::make('author')->shared()->required()->default('The newsroom'),
        ];
    }
}
