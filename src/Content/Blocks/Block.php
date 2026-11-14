<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Blocks;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Psyao\Kami\Content\Fields\Field;

/**
 * Something other than text inside a `RichText` document, listed in `kami.blocks` and offered with `RichText::make(...)->blocks([...])` (ADR 0016).
 *
 * Stored as `{"type": "block", "attrs": {"handle": …, "data": {…}}}`, its data keyed by Field handle; rendered through its view with that data typed.
 */
abstract class Block
{
    /** Stored in every document holding it: renaming it takes `ContentMigrator`. */
    abstract public function handle(): string;

    /**
     * Its Kami Fields, edited in the editor's modal and validated by `SaveEntry`: `->required()` at publication, other rules on every save.
     *
     * @return list<Field>
     */
    abstract public function fields(): array;

    /**
     * The Blade view rendering it, given its data by Field handle, typed as an Entry's values are: a Media Field's as `Media` (a Collection with `->multiple()`), an Entries or Terms Field's as a Collection of the visible ones, a RichText's as `Document`.
     */
    abstract public function view(): string;

    /**
     * One line telling it apart in the editor, beside its label, from its data as stored: a Media or Entries Field holds a list of ids. Kami escapes it.
     *
     * @param  array<mixed>  $data
     */
    public function summary(array $data): ?string
    {
        return null;
    }

    /** Its name in the editor: `kami-blocks.{handle}.label`, or the humanised handle. */
    final public function label(): string
    {
        return $this->translation('label') ?? $this->humanise($this->handle());
    }

    /** `kami-blocks.{handle}.fields.{field}.label`, or the humanised Field handle. A sub-Field is `{field}.fields.{sub}`. */
    final public function fieldLabel(string $field): string
    {
        return $this->translation("fields.{$field}.label") ?? $this->humanise(Str::afterLast($field, '.'));
    }

    /** `kami-blocks.{handle}.fields.{field}.help`, or none. */
    final public function fieldHelp(string $field): ?string
    {
        return $this->translation("fields.{$field}.help");
    }

    /** `kami-blocks.{handle}.fields.{field}.options.{option}`, or the humanised option key. */
    final public function optionLabel(string $field, string $option): string
    {
        return $this->translation("fields.{$field}.options.{$option}") ?? $this->humanise($option);
    }

    /**
     * @internal
     *
     * Its data as stored: its declared Fields only, each normalised as `SaveEntry` normalises a value.
     *
     * @param  array<mixed>  $data
     * @return array<string, mixed>
     */
    final public function normalise(array $data): array
    {
        $normalised = [];

        foreach ($this->fields() as $field) {
            $normalised[$field->handle] = $field->normalise($data[$field->handle] ?? null);
        }

        return $normalised;
    }

    private function humanise(string $key): string
    {
        return Str::ucfirst(Str::lower(Str::headline($key)));
    }

    /** The site's translation, then Kami's own for its Blocks. */
    private function translation(string $key): ?string
    {
        $key = "kami-blocks.{$this->handle()}.{$key}";

        foreach ([$key, "kami::{$key}"] as $candidate) {
            if (Lang::has($candidate)) {
                return (string) __($candidate);
            }
        }

        return null;
    }
}
