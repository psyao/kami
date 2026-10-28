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
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Content\Internal\EntryBuilder;
use Psyao\Kami\Content\Internal\Translation;
use Psyao\Kami\Content\Internal\Visibility;
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
 */
#[ScopedBy(Visibility::class)]
#[UseEloquentBuilder(EntryBuilder::class)]
#[Table(name: 'kami_entries')]
class Entry extends Model
{
    use HasUlids;

    private const array TRANSLATION_ATTRIBUTES = ['title', 'slug', 'status', 'published_at'];

    /** @internal */
    #[\Override]
    protected $with = ['translations'];

    /** @var array<string, array<string, mixed>> The rows of the type's table, by Locale. */
    private array $fieldRows = [];

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
        return resolve(ContentTypes::class)->find($this->type);
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
    public function getAttribute($key): mixed
    {
        if (in_array($key, self::TRANSLATION_ATTRIBUTES, true)) {
            return $this->translations->firstWhere('locale', Site::current()->currentLocale())?->getAttribute($key);
        }

        if (! array_key_exists($key, $this->attributes) && ! method_exists($this, $key)
            && in_array($key, array_map(fn (Field $field): string => $field->handle, $this->contentType()?->fields() ?? []), true)) {
            return $this->fieldRows[Site::current()->currentLocale()][$key] ?? null;
        }

        return parent::getAttribute($key);
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
