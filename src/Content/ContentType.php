<?php

declare(strict_types=1);

namespace Psyao\Kami\Content;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\Fields\RelationField;
use Symfony\Component\HttpFoundation\Response;

/** A kind of content, declared as a `final` class listed in `config/kami.php` under `types` (ADR 0007). */
abstract class ContentType
{
    /** The fixed start of the type's Paths; `null` takes the handle, `''` puts them at the root. */
    protected ?string $prefix = null;

    /** `false` gives the type no URL: no Path, no slug, `url()` null, its Entries listed on other pages. */
    protected bool $routable = true;

    /** `false` makes every Field Shared and gives each Entry a single `'*'` Translation, read in every Locale (ADR 0013). */
    protected bool $translatable = true;

    /** The snake_case of the class name; override it to keep the handle after renaming the class. */
    public function handle(): string
    {
        return Str::snake(class_basename(static::class));
    }

    /**
     * What the type stores, in the order of the form, optionally arranged in `Tab` and `Section`.
     *
     * @return list<Field|Layout>
     */
    public function fields(): array
    {
        return [];
    }

    /**
     * @internal
     *
     * The type's Fields whatever their layout: what every reader but the form's own goes through.
     *
     * @return list<Field>
     */
    final public function flatFields(): array
    {
        return array_values(array_filter($this->flatten($this->fields()), fn (Field | Layout $item): bool => $item instanceof Field));
    }

    /**
     * @internal
     *
     * The Fields stored in the type's table; with none, it has no table.
     *
     * @return list<Field>
     */
    final public function columnFields(): array
    {
        return array_values(array_filter($this->flatFields(), fn (Field $field): bool => ! $field instanceof RelationField));
    }

    /**
     * @internal
     *
     * @return list<RelationField>
     */
    final public function relationFields(): array
    {
        return array_values(array_filter($this->flatFields(), fn (Field $field): bool => $field instanceof RelationField));
    }

    /**
     * @internal
     *
     * @return list<Layout>
     */
    final public function layouts(): array
    {
        return array_values(array_filter($this->flatten($this->fields()), fn (Field | Layout $item): bool => $item instanceof Layout));
    }

    /** `kami-types.{handle}.tabs.{tab}.label`, or the humanised handle. */
    final public function tabLabel(string $tab): string
    {
        return $this->translation("tabs.{$tab}.label") ?? Str::headline($tab);
    }

    /** `kami-types.{handle}.sections.{section}.label`, or the humanised handle. */
    final public function sectionLabel(string $section): string
    {
        return $this->translation("sections.{$section}.label") ?? Str::headline($section);
    }

    /** The type's name in the admin: `kami-types.{handle}.label`, or the humanised handle. */
    final public function label(): string
    {
        return $this->translation('label') ?? Str::headline($this->handle());
    }

    /** `kami-types.{handle}.plural`, or the humanised handle in the plural. */
    final public function pluralLabel(): string
    {
        return $this->translation('plural') ?? Str::plural(Str::headline($this->handle()));
    }

    /** `kami-types.{handle}.fields.{field}.label`, or the humanised Field handle. A sub-Field is `{field}.fields.{sub}`. */
    final public function fieldLabel(string $field): string
    {
        return $this->translation("fields.{$field}.label") ?? Str::headline(Str::afterLast($field, '.'));
    }

    /** `kami-types.{handle}.fields.{field}.help`, or none. */
    final public function fieldHelp(string $field): ?string
    {
        return $this->translation("fields.{$field}.help");
    }

    /** `kami-types.{handle}.fields.{field}.options.{option}`, or the humanised option key. */
    final public function optionLabel(string $field, string $option): string
    {
        return $this->translation("fields.{$field}.options.{$option}") ?? Str::headline($option);
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

    /** @internal */
    public function isRoutable(): bool
    {
        return $this->routable;
    }

    /**
     * @internal
     *
     * Whether each Entry's Path ends in a slug of its own.
     */
    public function hasSlug(): bool
    {
        return $this->routable;
    }

    /**
     * @param  list<Field|Layout>  $declared
     * @return list<Field|Layout>
     */
    private function flatten(array $declared): array
    {
        $items = [];

        foreach ($declared as $item) {
            $items[] = $item;

            if ($item instanceof Layout) {
                array_push($items, ...$this->flatten($item->children));
            }
        }

        return $items;
    }

    /** Display strings come from the site's translation files, never from the declaration (ADR 0007); the package's own types have theirs in the package. */
    private function translation(string $key): ?string
    {
        $key = "kami-types.{$this->handle()}.{$key}";

        foreach ([$key, "kami::{$key}"] as $candidate) {
            if (Lang::has($candidate)) {
                return (string) __($candidate);
            }
        }

        return null;
    }
}
