<?php

declare(strict_types=1);

namespace Psyao\Kami\Content;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use LogicException;
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\Internal\Ancestors;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Content\Internal\EntryBuilder;
use Psyao\Kami\Content\Internal\References;
use Psyao\Kami\Content\Internal\Translation;
use Psyao\Kami\Content\Internal\Visibility;
use Psyao\Kami\Content\RichText\Document;
use Psyao\Kami\Routing\Internal\Path;
use Psyao\Kami\Routing\Internal\VisiblePaths;
use Psyao\Kami\Sites\Internal\Site;

/**
 * One content item in all its Locales, read in the current Locale; only visible Entries unless `withUnpublished()`, and never one in the Trash unless `withTrashed()` (ADR 0002, ADR 0012).
 *
 * Its title, slug, status, publication date and Field values read as attributes: `$entry->title`, `$entry->summary`.
 *
 * @property-read string $id
 * @property-read string $site
 * @property-read string $type The Content Type's handle.
 * @property-read string|null $title
 * @property-read string|null $slug
 * @property-read string|null $status `draft` or `published`.
 * @property-read CarbonImmutable|null $published_at
 * @property-read string|null $meta_title Its Meta title in the Locale it is read in, never another's.
 * @property-read string|null $meta_description Its Meta description in the Locale it is read in, never another's.
 * @property-read bool|null $noindex Whether it is Hidden from search engines in the Locale it is read in; `null` before it is saved.
 * @property-read int $position Its hand-set place among its siblings, on an orderable type.
 * @property-read string|null $parent_id Its Parent's id, on a hierarchical type.
 * @property-read int|string|null $created_by The Creator's User key.
 * @property-read int|string|null $updated_by The Last modifier's User key.
 * @property-read Carbon|null $updated_at When it was last saved, trashed or restored, in any Locale: `SaveEntry` refuses a save over a change made since the one its caller loaded.
 * @property-read Carbon|null $deleted_at When it went to the Trash; `null` outside it.
 */
#[ScopedBy(Visibility::class)]
#[UseEloquentBuilder(EntryBuilder::class)]
#[Table(name: 'kami_entries')]
class Entry extends Model
{
    use HasUlids;
    use SoftDeletes;

    /** @internal */
    #[\Override]
    protected $with = ['translations'];

    /** @var array<string, array<string, mixed>> The rows of the type's table, by Locale. */
    private array $fieldRows = [];

    /** The Locale it was queried in through `inLocale()`; the current Locale when null. */
    private ?string $locale = null;

    /** Queried through `withUnpublished()`: what it links to is read the same way. */
    private bool $unpublished = false;

    /** @var array<string, string|null>|null A nested Entry's Paths by Locale, null under a non-visible ancestor; read once, by `VisiblePaths`. */
    private ?array $visiblePaths = null;

    /**
     * @internal
     *
     * @return HasMany<Translation, $this>
     */
    public function translations(): HasMany
    {
        // Named: a subclass would derive `term_id`.
        return $this->hasMany(Translation::class, 'entry_id');
    }

    /**
     * Its Parent on a hierarchical type, visible in the Locale it is read in; `null` at the root. `with('parent')` loads a list's at once.
     *
     * @return BelongsTo<Entry, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->treeRead($this->belongsTo(Entry::class, 'parent_id', 'id', 'parent'));
    }

    /**
     * Its children on a hierarchical type, visible in the Locale it is read in, in Position order. `with('children')` loads a list's at once.
     *
     * @return HasMany<Entry, $this>
     */
    public function children(): HasMany
    {
        return $this->treeRead($this->hasMany(Entry::class, 'parent_id'))->ordered();
    }

    /**
     * Its ancestors, root first, on a hierarchical type: empty at the root, and under an ancestor not visible in its Locale, as `url()` is.
     *
     * On a type with URLs, one read of the rows at its Path's prefixes, which hold its ancestors' Paths (ADR 0008), whatever the depth;
     * an ancestor out of sight empties it, as it hides the Entry (ADR 0019). One query per level on a type without URLs.
     *
     * @return Collection<int, Entry>
     */
    public function ancestors(): Collection
    {
        if ($this->parent_id === null) {
            return new Collection;
        }

        $locale = $this->readLocale();
        // Visible ones, even under a Draft read through `withUnpublished()`: a Preview's breadcrumb links to pages that answer.
        $query = self::query()->readIn($locale);
        $prefixes = null;

        if ($this->contentType()?->isRoutable() === true) {
            $path = $this->visiblePath($locale);

            if ($path === null) {
                return new Collection;
            }

            $prefixes = array_slice(VisiblePaths::prefixes($path), 1);
            $query->whereIn('id', Path::query()->select('entry_id')->where(['site' => Site::current()->handle, 'locale' => $locale, 'status' => 200])->whereIn('path', $prefixes));
        } else {
            $query->whereKey(Ancestors::ids($this->parent_id));
        }

        $read = $query->get()->keyBy('id');
        $ancestors = [];

        // Up from the Parent: a link missing breaks the chain, and a row at a prefix that is no ancestor stays out.
        for ($id = $this->parent_id; $id !== null && ($ancestor = $read->pull($id)) instanceof self; $id = $ancestor->parent_id) {
            // Each ancestor sits one prefix higher, a slug holding no `/`: its URL in this Locale needs no query.
            if ($prefixes !== null) {
                $ancestor->setVisiblePaths([$locale => $prefixes[count($ancestors)]]);
            }

            $ancestors[] = $ancestor;
        }

        return new Collection($id === null ? array_reverse($ancestors) : []);
    }

    /**
     * A tree read: in the Locale the Entry is read in, through `withUnpublished()` when it was, with the Paths `url()` reads preloaded.
     *
     * @template TRelation of Relation<Entry, $this, mixed>
     *
     * @param  TRelation  $relation
     * @return TRelation
     */
    private function treeRead(Relation $relation): Relation
    {
        $query = $relation->getQuery();
        $query->readIn($this->readLocale())->preloadingPaths();

        if ($this->unpublished) {
            $query->withoutGlobalScope(Visibility::class);
        }

        return $relation;
    }

    /**
     * @internal
     *
     * Each row as its type's `$model`, whichever model queried it: a listing may mix types.
     *
     * @param  array<string, mixed>  $attributes
     * @param  string|null  $connection
     * @return static
     */
    public function newFromBuilder($attributes = [], $connection = null)
    {
        $type = ((array) $attributes)['type'] ?? null;
        $class = is_string($type) ? resolve(ContentTypes::class)->find($type)?->model() : null;

        // @phpstan-ignore return.type (a query on a subclass may meet another type's row, as `Project::query()` is no filter)
        return $class === null || $class === static::class ? parent::newFromBuilder($attributes, $connection) : (new $class)->newFromBuilder($attributes, $connection);
    }

    /** @internal */
    public function contentType(): ?ContentType
    {
        // From the attributes: reading `$this->type` asks for its Field, hence for this type. A partial select or a new Entry has none.
        $type = $this->attributes['type'] ?? null;

        return is_string($type) ? resolve(ContentTypes::class)->find($type) : null;
    }

    /**
     * @internal
     *
     * @param  array<string, array<string, mixed>>  $rows
     */
    public function setFieldRows(array $rows): void
    {
        // Over what is there: loading a relation Field again re-reads the columns, and must keep the others' target ids.
        foreach ($rows as $locale => $row) {
            $this->fieldRows[$locale] = [...$this->fieldRows[$locale] ?? [], ...$row];
        }
    }

    /** @internal */
    public function readIn(string $locale): void
    {
        $this->locale = $locale;
    }

    /** @internal */
    public function markUnpublishedRead(): void
    {
        $this->unpublished = true;
    }

    /** @internal */
    public function readsUnpublished(): bool
    {
        return $this->unpublished;
    }

    /**
     * @internal
     *
     * @param  array<string, list<string>>  $byLocale  A relation or Media Field's target ids, by Locale; they answer Fallback as a column's value does.
     * @param  array<string, Model>  $targets  What may be read, by id: an absent target is left out.
     */
    public function setReferences(string $handle, array $byLocale, array $targets): void
    {
        // A reload: a Locale emptied since holds no row to replace its old ids.
        foreach (array_keys($this->fieldRows) as $locale) {
            unset($this->fieldRows[$locale][$handle]);
        }

        foreach ($byLocale as $locale => $ids) {
            $this->fieldRows[$locale][$handle] = $ids === [] ? null : $ids;
        }

        $field = $this->field($handle);
        $locale = $field instanceof Field && $this->contentType()?->isTranslatable($field) ? $this->storedFallback($handle) ?? $this->readLocale() : '*';
        /** @var list<string> $ids */
        $ids = $this->fieldRows[$locale][$handle] ?? [];

        $this->setRelation($handle, new Collection(array_values(array_filter(array_map(fn (string $id): ?Model => $targets[$id] ?? null, $ids)))));
    }

    /** @internal */
    public function getAttribute($key): mixed
    {
        if (in_array($key, Translation::ATTRIBUTES, true)) {
            return $this->translation($this->readLocale())?->getAttribute($key);
        }

        // `summary` is both: the method is the type's hook, the attribute a Field a type may declare (#402).
        $field = array_key_exists($key, $this->attributes) || ($key !== 'summary' && method_exists($this, $key)) ? null : $this->field($key);

        if (! $field instanceof Field) {
            // Eloquent would take the `summary()` method for a relation and throw: absent, as any missing attribute.
            return $key === 'summary' ? $this->throwMissingAttributeExceptionIfApplicable($key) : parent::getAttribute($key);
        }

        if ($field->isReference()) {
            return $field->read($this->relationField($field));
        }

        if (! $this->contentType()?->isTranslatable($field)) {
            $value = $field->read($this->fieldRows['*'][$key] ?? null);

            return $value instanceof Document ? $value->readIn($this->readLocale()) : $value;
        }

        $fallback = $this->fallbackLocale($key);
        $value = $field->read($this->fieldRows[$fallback ?? $this->readLocale()][$key] ?? null);
        // A document links in the Locale its Entry is read in, even when its text fell back (ADR 0016).
        $value = $value instanceof Document ? $value->readIn($this->readLocale()) : $value;

        return $fallback !== null && ($value instanceof Document || $value instanceof InlineMarkdown) ? $value->inLanguage($fallback) : $value;
    }

    /**
     * The Locale a Translatable Field's value came from when it fell back to the default Locale, so a view can add `lang`; `null` otherwise.
     *
     * It falls back only when the value is blank and the default Locale's Translation is visible (ADR 0013).
     */
    public function fallbackLocale(string $field): ?string
    {
        $declared = $this->field($field);

        if ($declared instanceof Field && $declared->isReference()) {
            $this->relationField($declared);
        }

        return $this->storedFallback($field);
    }

    /**
     * ` lang="xx"` when a Field's value fell back to a Locale other than the page's, for the element that prints it; empty otherwise (ADR 0013).
     *
     * `<p{{ $entry->langAttribute('subtitle') }}>{{ $entry->subtitle }}</p>`. A RichText or Markdown value and `<x-kami::image>`'s alt text mark it themselves.
     */
    public function langAttribute(string $field): HtmlString
    {
        $locale = $this->fallbackLocale($field);

        return new HtmlString($locale === null || $locale === Site::current()->currentLocale() ? '' : ' lang="' . e($locale) . '"');
    }

    /** @return Collection<int, Model> */
    private function relationField(Field $field): Collection
    {
        if (! $this->relationLoaded($field->handle)) {
            if ($this->preventsLazyLoading) {
                $this->handleLazyLoadingViolation($field->handle);
            }

            References::load([$this], [$field->handle]);
        }

        /** @var Collection<int, Model> */
        return $this->getRelation($field->handle);
    }

    /** `fallbackLocale()` on what is already loaded: it never loads a relation Field. */
    private function storedFallback(string $field): ?string
    {
        $default = Site::current()->defaultLocale();
        $declared = $this->field($field);

        if (! $declared instanceof Field || ! $this->contentType()?->isTranslatable($declared) || $this->readLocale() === $default
            || ($this->fieldRows[$this->readLocale()][$field] ?? null) !== null || ($this->fieldRows[$default][$field] ?? null) === null) {
            return null;
        }

        return Visibility::isVisible($this->translations->firstWhere('locale', $default)) ? $default : null;
    }

    /** Its type's `summary()`: the page's description when it has no Meta description. `$entry->summary`, with no call, reads a Field of that name. */
    public function summary(): ?string
    {
        return $this->contentType()?->summary($this);
    }

    /** The absolute URL of the Entry in a Locale, the one it is read in by default; `null` when it or one of its ancestors is not visible there, or its type has no URL. */
    public function url(?string $locale = null): ?string
    {
        $site = Site::current();
        $locale ??= $this->readLocale();
        $translation = $this->translation($locale);
        $type = $this->contentType();

        if (! in_array($locale, $site->locales, true) || ! $type instanceof ContentType || ! $translation instanceof Translation || ! Visibility::isVisible($translation)) {
            return null;
        }

        $path = $type->isHierarchical() && $this->parent_id !== null ? $this->visiblePath($locale) : Path::of($type, $translation->slug);

        return $path === null ? null : $site->url($locale, $path);
    }

    /** A nested Entry's Path in a Locale, null under a non-visible ancestor. */
    private function visiblePath(string $locale): ?string
    {
        // ponytail: read on first call; `parent`, `children` and `ancestors()` preload theirs, a site's own query of nested Entries pays
        // two queries per Entry. V1 adds a public preload if a site's listing needs one.
        // `ancestors()` gives its Entries their Path in one Locale.
        if (! array_key_exists($locale, $this->visiblePaths ?? [])) {
            VisiblePaths::preload([$this]);
        }

        return $this->visiblePaths[$locale] ?? null;
    }

    /**
     * @internal
     *
     * @param  array<string, string|null>  $paths
     */
    public function setVisiblePaths(array $paths): void
    {
        $this->visiblePaths = $paths;
    }

    private function field(string $handle): ?Field
    {
        return collect($this->contentType()?->storedFields() ?? [])->firstWhere('handle', $handle);
    }

    /** @internal */
    public function readLocale(): string
    {
        return $this->locale ?? Site::current()->currentLocale();
    }

    /**
     * The Translation read in a Locale: that Locale's, or the `'*'` one of an untranslatable type.
     *
     * @internal
     */
    public function translation(string $locale): ?Translation
    {
        return $this->translations->firstWhere('locale', $locale) ?? $this->translations->firstWhere('locale', '*');
    }

    /**
     * Entries of one Content Type, named by class or handle.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function ofType(Builder $query, string $type): void
    {
        $this->entryBuilder($query)->forType($type);
    }

    /**
     * Entries whose `->filterable()` Field matches, after `ofType()`, taking an operator and a value as `where()` does.
     *
     * A Translatable Field reads its value in the Locale read, a Shared one its Shared value: stored values, with no Fallback.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function whereField(Builder $query, string $field, mixed $operator, mixed $value = null): void
    {
        // As given, so that `whereField('rank', 7)` reads as `where('rank', 7)`.
        $query->where($this->entryBuilder($query)->fieldColumn($field, sorting: false), ...array_slice(func_get_args(), 2));
    }

    /**
     * Entries ordered on a `->sortable()` Field, after `ofType()`, read as `whereField()` reads it.
     *
     * Paged with `paginate()`: `cursorPaginate()` throws, as a cursor skips Entries whose Field is blank.
     *
     * @param  Builder<static>  $query
     * @param  'asc'|'desc'  $direction
     */
    #[Scope]
    protected function orderByField(Builder $query, string $field, string $direction = 'asc'): void
    {
        $query->orderBy($this->entryBuilder($query)->fieldColumn($field, sorting: true), $direction);
    }

    /**
     * In the order editors chose; Entries sharing a Position in creation order, a ULID sorting by time.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }

    /**
     * Entries visible in a Locale, read in that Locale; with `withUnpublished()`, Entries with a Translation there.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function inLocale(Builder $query, string $locale): void
    {
        $this->entryBuilder($query)->readIn($locale)->whereHas('translations', fn (Builder $translations) => $translations->whereIn('locale', [$locale, '*']));
    }

    /**
     * Always an EntryBuilder (`UseEloquentBuilder`), which the scopes' public signatures do not name.
     *
     * @param  Builder<static>  $query
     */
    private function entryBuilder(Builder $query): EntryBuilder
    {
        return $query instanceof EntryBuilder ? $query : throw new LogicException('Entry queries use an EntryBuilder.');
    }

    /**
     * Drafts and Scheduled Entries too; not the Trash, which `withTrashed()` lifts.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function withUnpublished(Builder $query): void
    {
        $query->withoutGlobalScope(Visibility::class);
    }
}
