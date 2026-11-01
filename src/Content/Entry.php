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
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\Fields\RelationField;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Content\Internal\EntryBuilder;
use Psyao\Kami\Content\Internal\References;
use Psyao\Kami\Content\Internal\Translation;
use Psyao\Kami\Content\Internal\Visibility;
use Psyao\Kami\Content\RichText\Document;
use Psyao\Kami\Content\RichText\InlineMarkdown;
use Psyao\Kami\Routing\Internal\Path;
use Psyao\Kami\Sites\Internal\Site;

/**
 * One content item in all its Locales, read in the current Locale; only visible Entries unless `withUnpublished()` (ADR 0002, ADR 0012).
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
 * @property-read int $position Its hand-set place among its siblings, on an orderable type.
 * @property-read string|null $parent_id Its Parent's id, on a hierarchical type.
 * @property-read int|string|null $created_by The Creator's User key.
 * @property-read int|string|null $updated_by The Last modifier's User key.
 */
#[ScopedBy(Visibility::class)]
#[UseEloquentBuilder(EntryBuilder::class)]
#[Table(name: 'kami_entries')]
class Entry extends Model
{
    use HasUlids;

    /** @internal */
    #[\Override]
    protected $with = ['translations'];

    /** @var array<string, array<string, mixed>> The rows of the type's table, by Locale. */
    private array $fieldRows = [];

    /** The Locale it was queried in through `inLocale()`; the current Locale when null. */
    private ?string $locale = null;

    /** Queried through `withUnpublished()`: what it links to is read the same way. */
    private bool $unpublished = false;

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
     * @param  array<string, list<string>>  $byLocale  A relation Field's target ids, by Locale; they answer Fallback as a column's value does.
     * @param  array<string, self>  $targets  What may be read, by id: an absent target is left out.
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

        $this->setRelation($handle, $this->newCollection(array_values(array_filter(array_map(fn (string $id): ?self => $targets[$id] ?? null, $ids)))));
    }

    /** @internal */
    public function getAttribute($key): mixed
    {
        if (in_array($key, Translation::ATTRIBUTES, true)) {
            return $this->translation($this->readLocale())?->getAttribute($key);
        }

        $field = array_key_exists($key, $this->attributes) || method_exists($this, $key) ? null : $this->field($key);

        if (! $field instanceof Field) {
            return parent::getAttribute($key);
        }

        if ($field instanceof RelationField) {
            return $this->relationField($field);
        }

        if (! $this->contentType()?->isTranslatable($field)) {
            return $field->read($this->fieldRows['*'][$key] ?? null);
        }

        $fallback = $this->fallbackLocale($key);
        $value = $field->read($this->fieldRows[$fallback ?? $this->readLocale()][$key] ?? null);

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

        if ($declared instanceof RelationField) {
            $this->relationField($declared);
        }

        return $this->storedFallback($field);
    }

    /** @return Collection<int, self> */
    private function relationField(RelationField $field): Collection
    {
        if (! $this->relationLoaded($field->handle)) {
            if ($this->preventsLazyLoading) {
                $this->handleLazyLoadingViolation($field->handle);
            }

            References::load([$this], [$field->handle]);
        }

        /** @var Collection<int, self> */
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

    /** The absolute URL of the Entry in a Locale, the one it is read in by default; `null` when it is not visible there or its type has no URL. */
    public function url(?string $locale = null): ?string
    {
        $site = Site::current();
        $locale ??= $this->readLocale();
        $translation = $this->translation($locale);
        $type = $this->contentType();

        if (! in_array($locale, $site->locales, true) || ! $type instanceof ContentType || ! $translation instanceof Translation || ! Visibility::isVisible($translation)) {
            return null;
        }

        $path = Path::of($type, $translation->slug);

        return $path === null ? null : $site->url($locale, $path);
    }

    private function field(string $handle): ?Field
    {
        return collect($this->contentType()?->flatFields() ?? [])->firstWhere('handle', $handle);
    }

    /** @internal */
    public function readLocale(): string
    {
        return $this->locale ?? Site::current()->currentLocale();
    }

    /** The Translation read in a Locale: that Locale's, or the `'*'` one of an untranslatable type. */
    private function translation(string $locale): ?Translation
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
        $query->where('type', resolve(ContentTypes::class)->find($type)?->handle() ?? $type);
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
        // Always an EntryBuilder (`UseEloquentBuilder`), which the public signature does not name.
        if (! $query instanceof EntryBuilder) {
            throw new LogicException('Entry queries use an EntryBuilder.');
        }
        $query->readIn($locale)->whereHas('translations', fn (Builder $translations) => $translations->whereIn('locale', [$locale, '*']));
    }

    /**
     * Drafts and Scheduled Entries too.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function withUnpublished(Builder $query): void
    {
        $query->withoutGlobalScope(Visibility::class);
    }
}
