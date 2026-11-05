<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Fields;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Psyao\Kami\Content\Internal\References;
use Psyao\Kami\Media\Media as MediaItem;
use Psyao\Kami\Sites\Internal\Site;

/**
 * Links to Media items of the Site's library, kept in `kami_references` with no column in the type's table (ADR 0005).
 * Read as `?Media`, or with `->multiple()` as a Collection in pick order. Shared by default.
 */
final class Media extends Field
{
    /** @internal */
    #[\Override]
    protected bool $translatable = false;

    private bool $multiple = false;

    private bool $images = false;

    /** Holds an ordered list, read as a Collection. */
    public function multiple(): static
    {
        $this->multiple = true;

        return $this;
    }

    /** Accepts image Media only, checked on save. */
    public function images(): static
    {
        $this->images = true;

        return $this;
    }

    /** @internal */
    public function isMultiple(): bool
    {
        return $this->multiple;
    }

    /** @internal */
    public function acceptsImagesOnly(): bool
    {
        return $this->images;
    }

    /**
     * @internal
     *
     * What it may link to: in the current Site, of the image family under `->images()`.
     *
     * @return Builder<MediaItem>
     */
    public function linkable(): Builder
    {
        return MediaItem::query()->where('site', Site::current()->handle)->when($this->images, fn (Builder $query) => $query->where('family', 'image'));
    }

    /** @internal */
    #[\Override]
    public function isReference(): bool
    {
        return true;
    }

    /** @internal No column: every reader of the type's table goes through `ContentType::columnFields()`. */
    #[\Override]
    public function column(): string
    {
        return 'none';
    }

    /**
     * @internal
     *
     * Stored as a list either way: a single Field's id becomes a list of one.
     */
    #[\Override]
    public function normalise(mixed $value): mixed
    {
        return parent::normalise(match (true) {
            is_array($value) => array_values($value),
            is_string($value) && $value !== '' => [$value],
            default => $value,
        });
    }

    /**
     * @internal
     *
     * @return array<mixed>
     */
    #[\Override]
    protected function baseRules(): array
    {
        return ['array', ...$this->multiple ? [] : ['max:1'], References::targetsRule($this->linkable(...))];
    }

    /** Its targets, as `Entry` loads them: the first alone on a single Field. */
    #[\Override]
    protected function cast(mixed $value): mixed
    {
        return $this->multiple || ! $value instanceof Collection ? $value : $value->first();
    }
}
