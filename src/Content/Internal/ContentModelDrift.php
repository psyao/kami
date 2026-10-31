<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\Singleton;
use Psyao\Kami\Sites\Internal\Site;
use Psyao\Kami\Support\Internal\Doctor\Problem;
use Psyao\Kami\Support\Internal\Doctor\Severity;

/**
 * Where the declared Content Types and the database disagree (ADR 0006). `kami:install` fails on it after `migrate`, which may resolve it, and before anything is written; `kami:doctor` reports the same.
 *
 * @internal
 */
final readonly class ContentModelDrift
{
    private const string TABLE_PREFIX = 'kami_type_';

    private const string GUIDE = 'content-types.md#content-model-drift';

    public function __construct(private ContentTypes $types) {}

    /**
     * What fails a deploy, in a number of queries that follows the declarations, never the Entries: no row reaches PHP.
     *
     * @return list<Problem>
     */
    public function problems(): array
    {
        $problems = $this->undeclaredTables();

        foreach ($this->types->all() as $type) {
            array_push($problems, ...$this->columns($type), ...$this->translationShape($type));

            if ($type instanceof Singleton && $this->entries($type)->offset(1)->limit(1)->exists()) {
                $problems[] = $this->error(
                    "The Singleton {$type->handle()} has several Entries in the " . Site::current()->handle . ' Site, where its page and reads can only answer for one.',
                    'extend ContentType again, or delete the Entries to lose in a migration.',
                );
            }
        }

        return $problems;
    }

    /**
     * Values and links a Field no longer reads: kept and answering nowhere, so they warn without failing a deploy, as rows of an undeclared Locale do. Scans, so `kami:doctor` alone asks, and only once `problems()` is empty: every column then exists.
     *
     * @return list<Problem>
     */
    public function strandedValues(): array
    {
        $problems = [];

        foreach ($this->types->all() as $type) {
            foreach ($type->columnFields() as $field) {
                $query = DB::table(self::TABLE_PREFIX . $type->handle())->whereNotNull($field->handle);

                // ponytail: every Site's rows, as `ContentMigrator` converts them; V1 multisite takes a Site.
                if (($type->isTranslatable($field) ? $query->where('locale', '*') : $query->where('locale', '!=', '*'))->exists()) {
                    $problems[] = $this->stranded($type, $field, 'values');
                }
            }

            if (Schema::hasTable('kami_references')) {
                array_push($problems, ...$this->strandedLinks($type));
            }
        }

        return $problems;
    }

    /** @return list<Problem> */
    private function strandedLinks(ContentType $type): array
    {
        $fields = collect($type->relationFields())->keyBy(fn (Field $field): string => $field->handle);
        $links = $this->entries($type)->join('kami_references', 'kami_references.source_entry_id', '=', 'kami_entries.id')
            ->select('kami_references.field', 'kami_references.locale')->distinct()->get();
        $problems = [];

        foreach ($links as $link) {
            /** @var object{field: string, locale: string|null} $link */
            $field = $fields->get($link->field);

            if ($field === null) {
                $problems[] = new Problem(
                    Severity::Warning,
                    "Links of the type {$type->handle()} are under {$link->field}, which no relation Field declares: they are kept and no read finds them.",
                    "if the Field was renamed, call ContentMigrator::renameField('{$type->handle()}', '{$link->field}', '<handle>') in a migration; otherwise declare it again.",
                    self::GUIDE,
                );
            } elseif ($type->isTranslatable($field) === ($link->locale === null)) {
                $problems[] = $this->stranded($type, $field, 'links');
            }
        }

        return array_values(array_unique($problems, SORT_REGULAR));
    }

    private function stranded(ContentType $type, Field $field, string $what): Problem
    {
        $translatable = $type->isTranslatable($field);

        return new Problem(
            Severity::Warning,
            "The Field {$field->handle} of the type {$type->handle()} is " . ($translatable ? "translatable, but holds Shared {$what}" : "Shared, but holds {$what} in a Locale") . ': they are kept and no read finds them.',
            'call ' . $this->fieldConversion($type, $field, $translatable) . ' in a migration, or revert the declaration.',
            self::GUIDE,
        );
    }

    /** @return list<Problem> */
    private function undeclaredTables(): array
    {
        $prefix = DB::connection()->getTablePrefix() . self::TABLE_PREFIX;
        $declared = array_map(fn (ContentType $type): string => $prefix . $type->handle(), array_values($this->types->all()));
        $problems = [];

        // With no schema named, MySQL, MariaDB and PostgreSQL list what the database user sees in every other database too.
        foreach (array_unique(Schema::getTableListing(Schema::getCurrentSchemaListing(), schemaQualified: false)) as $table) {
            if (str_starts_with($table, $prefix) && ! in_array($table, $declared, true)) {
                $handle = substr($table, strlen($prefix));
                $problems[] = $this->error(
                    "The table {$table} belongs to no declared type.",
                    "declare the type again in config/kami.php under types, or call ContentMigrator::renameType('{$handle}', '<handle>') or, losing its values, ContentMigrator::dropType('{$handle}') in a migration.",
                );
            }
        }

        return $problems;
    }

    /** @return list<Problem> */
    private function columns(ContentType $type): array
    {
        $handle = $type->handle();
        $table = self::TABLE_PREFIX . $handle;
        $existing = [];

        foreach (Schema::getColumns($table) as $column) {
            if (! in_array($column['name'], ['entry_id', 'locale'], true)) {
                $existing[$column['name']] = ColumnTypes::of($column);
            }
        }

        $problems = [];
        $fix = "write the migration with php artisan kami:make:migration {$handle}, unless it is already written, for kami:install to run it.";

        foreach ($type->columnFields() as $field) {
            $column = $field->column();
            $reported = $existing[$field->handle] ?? null;

            if ($reported === null) {
                $problems[] = $this->error("The Field {$field->handle} of the type {$handle} has no column in {$table}.", $fix);
            } elseif ($reported !== $column && ($column !== 'json' || ! ColumnTypes::mayStoreJson($reported))) {
                $problems[] = $this->error("{$table}.{$field->handle} has type {$reported}, where the Field {$field->handle} of the type {$handle} declares {$column}.", $fix);
            }

            unset($existing[$field->handle]);
        }

        foreach (array_keys($existing) as $column) {
            $problems[] = $this->error("{$table}.{$column} belongs to no Field of the type {$handle}. Its values are kept.", "declare the Field again, or {$fix}");
        }

        return $problems;
    }

    /**
     * Left alone, `SyncPaths` would find no Translation of the declared shape and delete every 200 row of the type (#135).
     *
     * @return list<Problem>
     */
    private function translationShape(ContentType $type): array
    {
        $translatable = $type->isTranslatable();
        $translations = $this->entries($type)->join('kami_translations', 'kami_translations.entry_id', '=', 'kami_entries.id');

        if (! ($translatable ? $translations->where('kami_translations.locale', '*') : $translations->where('kami_translations.locale', '!=', '*'))->exists()) {
            return [];
        }

        $locale = Site::current()->defaultLocale();
        $handle = $type->handle();
        $fields = array_filter($type->flatFields(), fn (Field $field): bool => $field->isTranslatable());
        $calls = [
            'ContentMigrator::' . ($translatable ? "makeTypeTranslatable('{$handle}', locale: '{$locale}')" : "makeTypeShared('{$handle}', keep: '{$locale}')"),
            ...array_map(fn (Field $field): string => $this->fieldConversion($type, $field, $translatable), array_values($fields)),
        ];

        return [$this->error(
            "The type {$handle} is " . ($translatable ? 'translatable, but its Entries hold a Shared Translation' : 'not translatable, but its Entries hold Locale Translations') . ($type->isRoutable() ? ', and would lose their addresses.' : ', which no read finds.'),
            'call ' . implode(', ', $calls) . ' in a migration, or revert $translatable.',
        )];
    }

    /** The call moving a Field's rows to the scope it is declared in. */
    private function fieldConversion(ContentType $type, Field $field, bool $translatable): string
    {
        return $translatable
            ? "ContentMigrator::makeTranslatable('{$type->handle()}', '{$field->handle}')"
            : "ContentMigrator::makeShared('{$type->handle()}', '{$field->handle}', keep: '" . Site::current()->defaultLocale() . "')";
    }

    /** ponytail: the `default` Site only, as `Site::current()`; V1 multisite checks every Site's. */
    private function entries(ContentType $type): Builder
    {
        return DB::table('kami_entries')->where(['kami_entries.site' => Site::current()->handle, 'kami_entries.type' => $type->handle()]);
    }

    private function error(string $message, string $fix): Problem
    {
        return new Problem(Severity::Error, $message, $fix, self::GUIDE);
    }
}
