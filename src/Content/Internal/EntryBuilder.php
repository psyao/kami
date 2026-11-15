<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\Fields\Media;
use Psyao\Kami\Content\Fields\StructuredField;
use Psyao\Kami\Routing\Internal\VisiblePaths;
use Psyao\Kami\Sites\Internal\Site;

/**
 * Loads the Field values of the Entries it returns: one query per type present, never lazily (ADR 0006).
 *
 * @internal
 *
 * @extends Builder<Entry>
 */
final class EntryBuilder extends Builder
{
    private const string TRANSLATED = 'kami__translated';

    private const string SHARED = 'kami__shared';

    /** The Locale its Entries are checked and read in; the current Locale when null. */
    private ?string $locale = null;

    private bool $preloadsPaths = false;

    /** The type `ofType()` named, by class or handle, and the declared type it names. */
    private ?string $type = null;

    private ?ContentType $contentType = null;

    /** @var list<string> The aliases under which `whereField()` and `orderByField()` joined the type's table: one per row read. */
    private array $fieldRows = [];

    public function readIn(string $locale): static
    {
        if (in_array(self::TRANSLATED, $this->fieldRows, true) && $locale !== $this->locale()) {
            throw new LogicException('Call inLocale() before whereField() and orderByField(): their join already reads the Locale\'s row.');
        }

        $this->locale = $locale;

        return $this;
    }

    public function locale(): string
    {
        return $this->locale ?? Site::current()->currentLocale();
    }

    /** Entries of one type, named by class or handle; `whereField()` and `orderByField()` read its table. */
    public function forType(string $type): static
    {
        $this->type = $type;
        $this->contentType = resolve(ContentTypes::class)->find($type);

        return $this->where('type', $this->contentType?->handle() ?? $type);
    }

    /**
     * The column a listing filters or sorts on: the Field's, in the row of the Locale read for a Translatable Field, the `'*'` row for a
     * Shared one. Joins each row once, and selects `kami_entries.*` unless something is selected already (#231).
     */
    public function fieldColumn(string $handle, bool $sorting): string
    {
        [$contentType, $field] = $this->listedField($handle, $sorting);
        $locale = $contentType->isTranslatable($field) ? $this->locale() : '*';
        $alias = $locale === '*' ? self::SHARED : self::TRANSLATED;

        if (! in_array($alias, $this->fieldRows, true)) {
            $this->fieldRows[] = $alias;

            if ($this->getQuery()->columns === null) {
                $this->select('kami_entries.*');
            }

            $this->join("kami_type_{$contentType->handle()} as {$alias}", fn (JoinClause $join): JoinClause => $join
                ->on("{$alias}.entry_id", '=', 'kami_entries.id')->where("{$alias}.locale", $locale));
        }

        return "{$alias}.{$handle}";
    }

    /**
     * A Field in a column of the type's table, indexed for what the listing does with it.
     *
     * @return array{ContentType, Field}
     */
    private function listedField(string $handle, bool $sorting): array
    {
        [$scope, $call] = $sorting ? ['orderByField()', '->sortable()'] : ['whereField()', '->filterable()'];
        $type = $this->type ?? throw new LogicException("{$scope} needs ofType() first: it reads the type's table.");
        $contentType = $this->contentType ?? throw new LogicException("{$scope}: the type \"{$type}\" is not declared.");
        $prefix = "{$scope} on \"{$contentType->handle()}\":";
        $field = collect($contentType->storedFields())->firstWhere('handle', $handle);

        if (! $field instanceof Field) {
            $parent = collect($contentType->flatFields())->first(fn (Field $field): bool => $field instanceof StructuredField && array_key_exists($handle, $field->fields()));

            throw new InvalidArgumentException($parent instanceof Field
                ? "{$prefix} the Field \"{$handle}\" is inside \"{$parent->handle}\", in a json column no listing reads."
                : "{$prefix} the type has no Field \"{$handle}\".");
        }

        if ($field->isReference()) {
            $kind = $field instanceof Media ? 'the Media Field' : 'the relation';

            throw new InvalidArgumentException("{$prefix} {$kind} \"{$handle}\" has no column: it is kept in kami_references.");
        }

        if (! ($sorting ? $field->isSortable() : $field->isFilterable())) {
            throw new InvalidArgumentException("{$prefix} the Field \"{$handle}\" is not {$call}: declare it so, and kami:make:migration indexes its column.");
        }

        return [$contentType, $field];
    }

    /** Gives the nested Entries it returns their Paths, in one batch: what `url()` reads (ADR 0019). */
    public function preloadingPaths(): static
    {
        $this->preloadsPaths = true;

        return $this;
    }

    /**
     * `parent` and `children` read as the Entries they load from do: Eloquent builds an eager relation on a blank model, which holds neither.
     *
     * @param  string  $name
     */
    public function getRelation($name)
    {
        $relation = parent::getRelation($name);
        $query = $relation->getQuery();

        if (in_array($name, ['parent', 'children'], true) && $query instanceof self) {
            $query->readIn($this->locale());

            if (in_array(Visibility::class, $this->removedScopes(), true)) {
                $query->withoutGlobalScope(Visibility::class);
            }
        }

        return $relation;
    }

    /** A blank stored value is `NULL`, which no cursor condition reaches (#659). */
    public function cursorPaginate($perPage = null, $columns = ['*'], $cursorName = 'cursor', $cursor = null)
    {
        $onField = fn (array $order): bool => is_string($order['column'] ?? null) && Str::startsWith($order['column'], [self::TRANSLATED . '.', self::SHARED . '.']);

        if (collect($this->getQuery()->orders)->contains($onField)) {
            throw new LogicException('Page a query sorted with orderByField() with paginate(), not cursorPaginate(): a cursor skips Entries whose Field is blank.');
        }

        return parent::cursorPaginate($perPage, $columns, $cursorName, $cursor);
    }

    /** It hydrates without `eagerLoadRelations()`, so its Entries would read `null` for every stored Field value. */
    public function cursor(): never
    {
        throw new LogicException('Entries load their Field values in batches: use lazy() or chunk(), not cursor().');
    }

    public function eagerLoadRelations(array $models)
    {
        // A relation Field's handle is no method: it stays away from Eloquent, which would look for one.
        $requested = $this->eagerLoad;
        $handles = collect($models)->flatMap(fn (Entry $entry): array => $entry->contentType()?->relationFields() ?? [])
            ->map(fn (Field $field): string => $field->handle)
            ->filter(fn (string $handle): bool => array_key_exists($handle, $requested))->unique()->values()->all();
        $this->eagerLoad = array_diff_key($requested, array_flip($handles));
        $models = parent::eagerLoadRelations($models);
        $this->eagerLoad = $requested;

        if ($this->locale !== null) {
            array_walk($models, fn (Entry $entry) => $entry->readIn($this->locale));
        }

        if (in_array(Visibility::class, $this->removedScopes(), true)) {
            array_walk($models, fn (Entry $entry) => $entry->markUnpublishedRead());
        }

        $types = resolve(ContentTypes::class);

        foreach (collect($models)->groupBy(fn (Entry $entry): string => $entry->contentType()?->handle() ?? '') as $handle => $entries) {
            // An undeclared type, a partial select or a type with no column has no table to read.
            if (($types->find((string) $handle)?->columnFields() ?? []) === []) {
                continue;
            }

            $rows = [];

            foreach (DB::table("kami_type_{$handle}")->whereIn('entry_id', $entries->map(fn (Entry $entry) => $entry->id))->get() as $row) {
                /** @var array{entry_id: string, locale: string} $row */
                $row = (array) $row;
                $rows[$row['entry_id']][$row['locale']] = $row;
            }

            $entries->each(fn (Entry $entry) => $entry->setFieldRows($rows[$entry->id] ?? []));
        }

        if ($handles !== []) {
            References::load($models, $handles, array_keys(array_merge(...array_map($this->relationsNestedUnder(...), $handles))));
        }

        if ($this->preloadsPaths) {
            VisiblePaths::preload($models);
        }

        return $models;
    }
}
