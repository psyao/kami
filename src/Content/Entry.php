<?php

declare(strict_types=1);

namespace Psyao\Kami\Content;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Content\Internal\EntryBuilder;
use Psyao\Kami\Content\Internal\Translation;
use Psyao\Kami\Content\Internal\Visibility;
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

    /**
     * @internal
     *
     * @return HasMany<Translation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(Translation::class);
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
        $this->fieldRows = $rows;
    }

    /** @internal */
    public function readIn(string $locale): void
    {
        $this->locale = $locale;
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

        if (! $this->contentType()?->isTranslatable($field)) {
            return $field->read($this->fieldRows['*'][$key] ?? null);
        }

        $locale = $this->fallbackLocale($key) ?? $this->readLocale();

        return $field->read($this->fieldRows[$locale][$key] ?? null);
    }

    /**
     * The Locale a Translatable Field's value came from when it fell back to the default Locale, so a view can add `lang`; `null` otherwise.
     *
     * It falls back only when the value is blank and the default Locale's Translation is visible (ADR 0013).
     */
    public function fallbackLocale(string $field): ?string
    {
        $default = Site::current()->defaultLocale();
        $declared = $this->field($field);

        if (! $declared instanceof Field || ! $this->contentType()?->isTranslatable($declared) || $this->readLocale() === $default
            || ($this->fieldRows[$this->readLocale()][$field] ?? null) !== null || ($this->fieldRows[$default][$field] ?? null) === null) {
            return null;
        }

        return Visibility::isVisible($this->translations->firstWhere('locale', $default)) ? $default : null;
    }

    /** The absolute URL of the Entry in a Locale, the one it is read in by default; `null` when it is not visible there. */
    public function url(?string $locale = null): ?string
    {
        $site = Site::current();
        $locale ??= $this->readLocale();
        $translation = $this->translation($locale);
        $type = $this->contentType();

        if (! in_array($locale, $site->locales, true) || ! $type instanceof ContentType || ! $translation instanceof Translation || ! Visibility::isVisible($translation) || $translation->slug === null) {
            return null;
        }

        return $site->url($locale, Path::of($type, $translation->slug));
    }

    private function field(string $handle): ?Field
    {
        return collect($this->contentType()?->fields() ?? [])->firstWhere('handle', $handle);
    }

    private function readLocale(): string
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
