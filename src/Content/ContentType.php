<?php

declare(strict_types=1);

namespace Psyao\Kami\Content;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\Fields\Media;
use Psyao\Kami\Content\Fields\RelationField;
use Psyao\Kami\Content\Fields\RichText;
use Psyao\Kami\Content\RichText\Document;
use Psyao\Kami\Media\Media as MediaItem;
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

    /** `true` lets editors drag the type's rows, and `ordered()` read that order; a new Entry goes last. */
    protected bool $orderable = false;

    /** `true` lets an Entry sit under another of the same type and Site. Add `$orderable` to order each Parent's children by hand. */
    protected bool $hierarchical = false;

    /** `true` lists its visible Entries at its Prefix, under every Locale segment, through `renderArchive()`: a routable type with a Prefix of its own (ADR 0009). */
    protected bool $archive = false;

    /** The Entries on each page of its Archive. */
    protected int $perPage = 10;

    /** `true` lists its 20 latest visible Entries as RSS 2.0 at `{prefix}/feed`, and in the Site's `/feed`, under every Locale segment: a routable type with a Prefix of its own (ADR 0009). */
    protected bool $feed = false;

    /** Its pages' `og:type`; `article` adds `article:published_time` and `article:modified_time`. */
    protected string $ogType = 'website';

    /**
     * The `Entry` subclass its Entries hydrate to, for domain methods; `null` takes `Entry`.
     *
     * @var class-string<Entry>|null
     */
    protected ?string $model = null;

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
        return array_values(array_filter($this->flatFields(), fn (Field $field): bool => ! $field->isReference()));
    }

    /**
     * @internal
     *
     * The Fields stored in `kami_references`: relation and Media Fields.
     *
     * @return list<RelationField|Media>
     */
    final public function relationFields(): array
    {
        /** @var list<RelationField|Media> */
        return array_values(array_filter($this->storedFields(), fn (Field $field): bool => $field->isReference()));
    }

    /**
     * @internal
     *
     * Its Fields and, on a routable type, the Social image: a Shared, images-only Media Field under the reserved `seo_image`, which
     * `SaveEntry` writes and an Entry reads as any other, and which the form leaves to its SEO section (#23 decision 1).
     *
     * @return list<Field>
     */
    final public function storedFields(): array
    {
        return [...$this->flatFields(), ...$this->isRoutable() ? [Media::make('seo_image')->images()] : []];
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
        return $this->translation("tabs.{$tab}.label") ?? $this->humanise($tab);
    }

    /** `kami-types.{handle}.sections.{section}.label`, or the humanised handle. */
    final public function sectionLabel(string $section): string
    {
        return $this->translation("sections.{$section}.label") ?? $this->humanise($section);
    }

    /** The type's name in the admin: `kami-types.{handle}.label`, or the humanised handle. */
    final public function label(): string
    {
        return $this->translation('label') ?? $this->humanise($this->handle());
    }

    /** `kami-types.{handle}.plural`, or the humanised handle in the plural. */
    final public function pluralLabel(): string
    {
        return $this->translation('plural') ?? Str::plural($this->humanise($this->handle()));
    }

    /** `kami-types.{handle}.fields.{field}.label`, or the humanised Field handle. A sub-Field is `{field}.fields.{sub}`. */
    final public function fieldLabel(string $field): string
    {
        return $this->translation("fields.{$field}.label") ?? $this->humanise(Str::afterLast($field, '.'));
    }

    /** `kami-types.{handle}.fields.{field}.help`, or none. */
    final public function fieldHelp(string $field): ?string
    {
        return $this->translation("fields.{$field}.help");
    }

    /** `kami-types.{handle}.fields.{field}.options.{option}`, or the humanised option key. */
    final public function optionLabel(string $field, string $option): string
    {
        return $this->translation("fields.{$field}.options.{$option}") ?? $this->humanise($option);
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
     * Turns a page of its Archive into the response at its Prefix (ADR 0009): `$entries` holds the visible Entries, newest first, or in Position order on an orderable type.
     *
     * Kami calls it through the container, as `render()`.
     *
     * @param  CursorPaginator<int, Entry>  $entries
     */
    public function renderArchive(CursorPaginator $entries): View | Response
    {
        return view()->make("kami.{$this->handle()}-archive", ['entries' => $entries]);
    }

    /**
     * The page's description when it has no Meta description, exposed as `$entry->summary()`: by default the start of its first RichText Field's paragraphs, in its own Locale only.
     *
     * Headings, code blocks, lists, quotes and Blocks are left out (#467).
     *
     * Override it to read another Field, as `return $entry->summary;` for a Field of that name.
     */
    public function summary(Entry $entry): ?string
    {
        $field = collect($this->flatFields())->first(fn (Field $field): bool => $field instanceof RichText);
        // A value fallen back from the default Locale would describe this page in another language.
        $document = $field === null || $entry->fallbackLocale($field->handle) !== null ? null : $entry->getAttribute($field->handle);
        $nodes = $document instanceof Document ? $document->content()['content'] ?? [] : [];
        $paragraphs = array_filter(is_array($nodes) ? $nodes : [], fn (mixed $node): bool => data_get($node, 'type') === 'paragraph');
        $excerpt = new Document(['type' => 'doc', 'content' => array_values($paragraphs)])->excerpt(160);

        return $excerpt === '' ? null : $excerpt;
    }

    /**
     * The page's Social image when its Entry has none of its own, before the Site's default one: `null` by default.
     *
     * Override it to return an image Field's value, such as a cover's. An image with no Conversion, an SVG, is skipped.
     */
    public function socialImage(Entry $entry): ?MediaItem
    {
        return null;
    }

    /** @internal */
    final public function ogType(): string
    {
        return $this->ogType;
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

    /**
     * @internal
     *
     * @return class-string<Entry>
     */
    public function model(): string
    {
        return $this->model ?? Entry::class;
    }

    /** @internal */
    public function isOrderable(): bool
    {
        return $this->orderable;
    }

    /** @internal */
    public function hasArchive(): bool
    {
        return $this->archive;
    }

    /** @internal */
    public function hasFeed(): bool
    {
        return $this->feed;
    }

    /** @internal */
    public function perPage(): int
    {
        return $this->perPage;
    }

    /** @internal */
    public function isHierarchical(): bool
    {
        return $this->hierarchical;
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

    /** In sentence case, as Filament humanises its own: `news_item` reads `News item`. */
    private function humanise(string $key): string
    {
        return Str::ucfirst(Str::lower(Str::headline($key)));
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
