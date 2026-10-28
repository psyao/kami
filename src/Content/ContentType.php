<?php

declare(strict_types=1);

namespace Psyao\Kami\Content;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Psyao\Kami\Content\Fields\Field;
use Symfony\Component\HttpFoundation\Response;

/** A kind of content, declared as a `final` class listed in `config/kami.php` under `types` (ADR 0007). */
abstract class ContentType
{
    /** The fixed start of the type's Paths; `null` takes the handle, `''` puts them at the root. */
    protected ?string $prefix = null;

    /** `false` makes every Field Shared and gives each Entry a single `'*'` Translation, read in every Locale (ADR 0013). */
    protected bool $translatable = true;

    /** The snake_case of the class name; override it to keep the handle after renaming the class. */
    public function handle(): string
    {
        return Str::snake(class_basename(static::class));
    }

    /** @return list<Field> */
    public function fields(): array
    {
        return [];
    }

    /**
     * Turns a visible Entry into the response at its Path (ADR 0009).
     *
     * Kami calls it through the container: an override may take more dependencies, each with a default (`?Request $request = null`), as PHP requires of an override.
     */
    public function render(Entry $entry): View | Response
    {
        return view()->make("kami.{$this->handle()}", ['entry' => $entry]);
    }

    /**
     * @internal
     *
     * Whether the type, or one of its Fields, holds one value per Locale.
     */
    public function isTranslatable(?Field $field = null): bool
    {
        return $this->translatable && ($field?->isTranslatable() ?? true);
    }

    /** @internal */
    public function prefix(): string
    {
        return $this->prefix ?? $this->handle();
    }
}
