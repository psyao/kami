<?php

// PROTOTYPE. psyao.ch's Content Types, as a site would write them in app/Content/.

namespace PsyaoCh\Content; // App\Content in the real site

use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Fields\Entries;
use Psyao\Kami\Content\Fields\RichText;
use Psyao\Kami\Content\Taxonomy;
use Psyao\Kami\Content\Blocks\Video;

final class Post extends ContentType
{
    protected ?string $prefix = 'articles';

    public function fields(): array
    {
        return [
            RichText::make('body')->blocks([Video::class])->code()->required(),
            Entries::make('categories')->of(Category::class)->multiple(),
            Entries::make('tags')->of(Tag::class)->multiple()->creatable(),
        ];
    }
}

final class Page extends ContentType
{
    protected ?string $prefix = '';
    protected bool $hierarchical = true;

    public function fields(): array
    {
        return [
            RichText::make('body')->code(),
        ];
    }
}

final class Category extends Taxonomy
{
    protected ?string $prefix = 'category';
    protected bool $hierarchical = true;
}

final class Tag extends Taxonomy
{
    protected ?string $prefix = 'tag';
}
