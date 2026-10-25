<?php

// PROTOTYPE. steve-et-aline.ch's Gallery and its Block, as a site would write them.
// Post, Page, Category and Tag are the same shape as psyao.ch's.

namespace SteveEtAlineCh\Content; // App\Content in the real site

use Psyao\Kami\Content\Block;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Fields\Entries;
use Psyao\Kami\Content\Fields\Media;
use Psyao\Kami\Content\Fields\Textarea;

/** An album is a Gallery holding Galleries, with no photos of its own. */
final class Gallery extends ContentType
{
    protected ?string $prefix = 'photos';
    protected bool $hierarchical = true;
    protected bool $orderable = true;

    public function fields(): array
    {
        return [
            Media::make('cover')->images(),
            Media::make('photos')->images()->multiple(),
            Textarea::make('description'),
        ];
    }

    // SEO image from cover, description from description: owned by "SEO", shown here
    // only as the kind of hook a type may override.
    public function seoImage(): string { return 'cover'; }
    public function seoDescription(): string { return 'description'; }
}

/** Offered in Page and Post bodies: RichText::make('body')->blocks([GalleryBlock::class, Video::class]). */
final class GalleryBlock extends Block
{
    public function handle(): string
    {
        return 'gallery'; // stored in every node
    }

    public function fields(): array
    {
        return [
            Entries::make('gallery')->of(Gallery::class)->required(),
        ];
    }
}
