<?php

declare(strict_types=1);

namespace Psyao\Kami\Schema;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Content\Internal\MakeMigrationCommand;
use Psyao\Kami\Sites\Internal\Site;
use RuntimeException;

/**
 * What a migration calls to move data a schema change cannot express (ADR 0006). Each operation goes in batches through the query builder, with no JSON function, no model event and no `updated_at` change. None has a `down()`: going back is a new migration with the inverse operation.
 */
final class ContentMigrator
{
    // ponytail: Locales are the `default` Site's, and rows every Site's; V1 multisite takes a Site.

    private const int BATCH = 500;

    private const string REFERENCES = 'kami_references';

    private const string SHARED = '*';

    /** Moves a type's Entries and its table to a new handle, with the index and foreign key names that follow the old one. */
    public static function renameType(string $from, string $to): void
    {
        resolve(ContentTypes::class)->validateHandle(self::class, 'the handle', $to);
        [$old, $new] = [self::table($from), self::table($to)];
        $hasTable = Schema::hasTable($old);

        if (Schema::hasTable($new) || DB::table('kami_entries')->where('type', $to)->exists()) {
            throw new InvalidArgumentException("The type {$from} cannot become {$to}: {$new} or Entries of {$to} already exist.");
        }

        if (! $hasTable && ! DB::table('kami_entries')->where('type', $from)->exists()) {
            throw new InvalidArgumentException("The type {$from} has neither a table nor Entries.");
        }

        if ($hasTable) {
            Schema::rename($old, $new);
            self::renameConstraints($old, $new);
        }

        self::eachBatch($from, fn (array $ids) => DB::table('kami_entries')->whereIn('id', $ids)->update(['type' => $to]));
    }

    /** Renames a Field: its column and that column's index when it has one, else a relation Field's rows in `kami_references`. */
    public static function renameField(string $type, string $from, string $to): void
    {
        resolve(ContentTypes::class)->validateHandle(self::class, 'the Field handle', $to);
        $table = self::table($type);

        if (array_intersect([$from, $to], ['entry_id', 'locale']) !== []) {
            throw new InvalidArgumentException("{$table}.entry_id and {$table}.locale hold no Field.");
        }

        if (Schema::hasColumn($table, $to)) {
            throw new InvalidArgumentException("{$table}.{$from} cannot become {$to}: that column already exists.");
        }

        if (! Schema::hasColumn($table, $from)) {
            self::eachBatch($type, function (array $ids) use ($type, $from, $to): void {
                if (DB::table(self::REFERENCES)->whereIn('source_entry_id', $ids)->where('field', $to)->exists()) {
                    throw new InvalidArgumentException("The relation Field {$from} of {$type} cannot become {$to}: that Field already holds links.");
                }
            });

            self::eachBatch($type, fn (array $ids) => DB::table(self::REFERENCES)->whereIn('source_entry_id', $ids)->where('field', $from)->update(['field' => $to]));

            return;
        }

        $index = MakeMigrationCommand::indexName($table, $from);
        $indexed = in_array($index, array_column(Schema::getIndexes($table), 'name'), true);

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->renameColumn($from, $to));

        if ($indexed) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->renameIndex($index, MakeMigrationCommand::indexName($table, $to)));
        }
    }

    /** Destroys a type: its table, its Entries and what cascades from them, such as the links other types hold to them. A type still declared is refused. */
    public static function dropType(string $type): void
    {
        if (collect(resolve(ContentTypes::class)->declared())->contains(fn (ContentType $declared): bool => $declared->handle() === $type)) {
            throw new InvalidArgumentException("The type {$type} is still declared in kami.types: remove it before dropping it.");
        }

        Schema::dropIfExists(self::table($type));

        // Both restrict deleting an Entry. A batch's Parent may sit in a later batch, so they clear first.
        self::eachBatch($type, function (array $ids): void {
            DB::table('kami_paths')->whereIn('entry_id', $ids)->delete();
            DB::table('kami_entries')->whereIn('id', $ids)->update(['parent_id' => null]);
        });

        self::eachBatch($type, fn (array $ids) => DB::table('kami_entries')->whereIn('id', $ids)->delete());
    }

    /** Destroys a Locale: its translations, its rows in every type's table, its references and its paths. The Entries stay. A Locale still declared is refused, as is the Shared `'*'`. */
    public static function dropLocale(string $locale): void
    {
        if ($locale === self::SHARED || in_array($locale, Site::current()->locales, true)) {
            throw new InvalidArgumentException("The Locale {$locale} is still in use: remove it from kami.sites before dropping it.");
        }

        self::deleteRows('kami_translations', 'entry_id', $locale);
        self::deleteRows(self::REFERENCES, 'id', $locale);
        self::deleteRows('kami_paths', 'id', $locale);

        // Every type holding Entries, declared or not: a type with none has no row.
        foreach (DB::table('kami_entries')->distinct()->pluck('type') as $type) {
            /** @var string $type */
            if (Schema::hasTable(self::table($type))) {
                self::deleteRows(self::table($type), 'entry_id', $locale);
            }
        }
    }

    /**
     * Makes a Shared Field translatable: its value, or a relation Field's links, moves to the declared Locales where the Entry has a translation, to the default Locale when it has none.
     *
     * @throws RuntimeException While a Locale already holds something else, before anything is written.
     */
    public static function makeTranslatable(string $type, string $field): void
    {
        $table = self::table($type);
        $column = Schema::hasColumn($table, $field);

        self::eachBatch($type, function (array $ids) use ($type, $table, $field, $column): void {
            foreach ($column ? self::values($table, $field, $ids) : self::links($field, $ids) as $entry => $held) {
                if (isset($held[self::SHARED])) {
                    self::refuseDifference($type, $field, self::SHARED, $entry, $held);
                }
            }
        });

        self::eachBatch($type, function (array $ids) use ($table, $field, $column): void {
            $locales = self::localesOf($ids);

            if ($column) {
                $rows = [];

                foreach (DB::table($table)->whereIn('entry_id', $ids)->where('locale', self::SHARED)->whereNotNull($field)->get() as $row) {
                    /** @var object{entry_id: string} $row */
                    $entry = $row->entry_id;

                    foreach ($locales[$entry] as $locale) {
                        $rows[] = ['entry_id' => $row->entry_id, 'locale' => $locale, $field => $row->{$field}];
                    }
                }

                DB::table($table)->upsert($rows, ['entry_id', 'locale'], [$field]);
                DB::table($table)->whereIn('entry_id', $ids)->where('locale', self::SHARED)->update([$field => null]);

                return;
            }

            $links = DB::table(self::REFERENCES)->where('field', $field);
            $shared = (clone $links)->whereIn('source_entry_id', $ids)->whereNull('locale');
            $rows = [];

            foreach ($shared->get() as $link) {
                /** @var object{source_entry_id: string} $link */
                $entry = $link->source_entry_id;

                foreach ($locales[$entry] as $locale) {
                    $rows[] = [...(array) $link, 'id' => (string) Str::ulid(), 'locale' => $locale];
                }
            }

            // What a Locale holds next to shared links was found the same above: the copies replace it, rather than doubling it.
            (clone $links)->whereIn('source_entry_id', (clone $shared)->pluck('source_entry_id')->all())->whereNotNull('locale')->delete();
            $shared->delete();

            foreach (array_chunk($rows, self::BATCH) as $chunk) {
                DB::table(self::REFERENCES)->insert($chunk);
            }
        });
    }

    /**
     * Makes a translatable Field Shared with one Locale's value, or a relation Field's links.
     *
     * @throws RuntimeException While another Locale holds a different value, before anything is written, unless `$discardOthers`.
     */
    public static function makeShared(string $type, string $field, string $keep, bool $discardOthers = false): void
    {
        $table = self::table($type);
        $column = Schema::hasColumn($table, $field);

        self::refuseLoss($type, $field, $keep, $discardOthers, fn (array $ids): array => $column ? self::values($table, $field, $ids) : self::links($field, $ids));

        if (! $column) {
            self::eachBatch($type, function (array $ids) use ($field, $keep): void {
                $links = fn (array $entries) => DB::table(self::REFERENCES)->whereIn('source_entry_id', $entries)->where('field', $field);

                $links($links($ids)->where('locale', $keep)->distinct()->pluck('source_entry_id')->all())->whereNull('locale')->delete();
                $links($ids)->where('locale', $keep)->update(['locale' => null]);
                $links($ids)->whereNotNull('locale')->delete();
            });

            return;
        }

        self::eachBatch($type, function (array $ids) use ($table, $field, $keep): void {
            $rows = DB::table($table)->whereIn('entry_id', $ids)->where('locale', $keep)->whereNotNull($field)->get()
                ->map(fn (object $row): array => ['entry_id' => $row->entry_id, 'locale' => self::SHARED, $field => $row->{$field}])->all();

            DB::table($table)->upsert($rows, ['entry_id', 'locale'], [$field]);
            DB::table($table)->whereIn('entry_id', $ids)->where('locale', '!=', self::SHARED)->update([$field => null]);
        });
    }

    /** Moves each shared translation to a Locale, for a type whose `$translatable` turned true. Field rows stay: `makeTranslatable()` moves them, Field by Field. */
    public static function makeTypeTranslatable(string $type, string $locale): void
    {
        if (! in_array($locale, Site::current()->locales, true)) {
            throw new InvalidArgumentException("The Locale {$locale} is not declared in kami.sites.");
        }

        self::eachBatch($type, fn (array $ids) => DB::table('kami_translations')->whereIn('entry_id', $ids)->where('locale', self::SHARED)->update(['locale' => $locale]));
    }

    /**
     * Keeps one Locale's translation as the shared one, for a type whose `$translatable` turned false. Field rows stay: `makeShared()` moves them, Field by Field.
     *
     * @throws RuntimeException While another Locale's differs, before anything is written, unless `$discardOthers`, which leaves an Entry with none in that Locale without a translation.
     */
    public static function makeTypeShared(string $type, string $keep, bool $discardOthers = false): void
    {
        self::refuseLoss($type, 'its Translations', $keep, $discardOthers, fn (array $ids): array => DB::table('kami_translations')->whereIn('entry_id', $ids)->get()->groupBy('entry_id')->map(
            fn (Collection $rows): array => $rows->keyBy('locale')->map(fn (object $row): array => Arr::except((array) $row, ['id', 'entry_id', 'locale', 'created_at', 'updated_at']))->all(),
        )->all());

        self::eachBatch($type, function (array $ids) use ($keep): void {
            $translations = fn (array $entries) => DB::table('kami_translations')->whereIn('entry_id', $entries);

            $translations($translations($ids)->where('locale', $keep)->pluck('entry_id')->all())->where('locale', self::SHARED)->delete();
            $translations($ids)->whereNotIn('locale', [$keep, self::SHARED])->delete();
            $translations($ids)->where('locale', $keep)->update(['locale' => self::SHARED]);
        });
    }

    /**
     * Checks every Entry before a conversion writes. An Entry holding only what is already shared has nothing to lose, so an operation that stopped can run again.
     *
     * @param  Closure(array<array-key, string>): array<array-key, array<array-key, mixed>>  $held  What each Entry holds, by Locale.
     */
    private static function refuseLoss(string $type, string $what, string $keep, bool $discardOthers, Closure $held): void
    {
        $unkept = $kept = false;

        self::eachBatch($type, function (array $ids) use ($type, $what, $keep, $discardOthers, $held, &$unkept, &$kept): void {
            foreach ($held($ids) as $entry => $values) {
                if (array_keys($values) === [self::SHARED]) {
                    continue;
                }

                $unkept = true;
                $kept = $kept || isset($values[$keep]);

                if (! $discardOthers) {
                    self::refuseDifference($type, $what, $keep, $entry, $values);
                }
            }
        });

        // Discarding toward a mistyped Locale would keep nothing. A Locale Entries hold passes even undeclared: it may be why the type is converted.
        if ($keep === self::SHARED || ($unkept && ! $kept)) {
            throw new InvalidArgumentException("The type {$type} cannot keep {$keep}: no Locale of that name holds {$what}.");
        }
    }

    /** @param  array<array-key, mixed>  $values */
    private static function refuseDifference(string $type, string $what, string $keep, int | string $entry, array $values): void
    {
        foreach ($values as $locale => $value) {
            if (! self::same($value, $values[$keep] ?? null)) {
                throw new RuntimeException("The type {$type} cannot convert {$what}: Entry {$entry} holds in {$locale} what {$keep} does not, which would be lost. makeShared and makeTypeShared take discardOthers: true to lose it.");
            }
        }
    }

    /** Values compare exactly, json decoded with its keys sorted: MySQL does not keep their order (ADR 0006). */
    private static function same(mixed $a, mixed $b): bool
    {
        if ($a === $b) {
            return true;
        }

        if (! is_string($a) || ! is_string($b)) {
            return false;
        }

        [$a, $b] = [json_decode($a, true), json_decode($b, true)];

        return is_array($a) && is_array($b) && self::sorted($a) === self::sorted($b);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function sorted(array $value): array
    {
        ksort($value);

        return array_map(fn (mixed $item): mixed => is_array($item) ? self::sorted($item) : $item, $value);
    }

    /**
     * @param  array<array-key, string>  $ids
     * @return array<array-key, array<array-key, mixed>> By Entry and Locale, leaving out nulls.
     */
    private static function values(string $table, string $field, array $ids): array
    {
        return DB::table($table)->whereIn('entry_id', $ids)->whereNotNull($field)->get()->groupBy('entry_id')
            ->map(fn (Collection $rows): array => $rows->pluck($field, 'locale')->all())->all();
    }

    /**
     * @param  array<array-key, string>  $ids
     * @return array<array-key, array<array-key, mixed>> By Entry and Locale, `'*'` when shared, the targets in pick order.
     */
    private static function links(string $field, array $ids): array
    {
        return DB::table(self::REFERENCES)->whereIn('source_entry_id', $ids)->where('field', $field)->orderBy('position')->get()->groupBy('source_entry_id')->map(
            fn (Collection $rows): array => $rows->groupBy(fn (object $link): string => is_string($link->locale) ? $link->locale : self::SHARED)
                ->map(fn (Collection $held): array => $held->map(fn (object $link): mixed => $link->target_entry_id ?? $link->target_media_id)->all())->all(),
        )->all();
    }

    /**
     * @param  array<array-key, string>  $ids
     * @return array<array-key, array<array-key, string>> By Entry, the declared Locales it has a translation in, or the default one.
     */
    private static function localesOf(array $ids): array
    {
        $site = Site::current();

        return [
            ...array_fill_keys($ids, [$site->defaultLocale()]),
            ...DB::table('kami_translations')->whereIn('entry_id', $ids)->whereIn('locale', $site->locales)->get()->groupBy('entry_id')
                ->map(fn (Collection $rows): array => array_map(strval(...), $rows->pluck('locale')->all()))->all(),
        ];
    }

    /** A delete with a limit does not exist on PostgreSQL. */
    private static function deleteRows(string $table, string $key, string $locale): void
    {
        do {
            $keys = DB::table($table)->where('locale', $locale)->limit(self::BATCH)->pluck($key)->all();
            DB::table($table)->where('locale', $locale)->whereIn($key, $keys)->delete();
        } while ($keys !== []);
    }

    /** A name kept from the old handle would refuse a new type taking that handle (#194). SQLite reports no foreign key name, and holds none to collide. */
    private static function renameConstraints(string $old, string $new): void
    {
        $follows = fn (string $name, string $columns, string $prefix): bool => $name === MakeMigrationCommand::indexName($old, $columns, $prefix) || str_starts_with($name, "{$old}_");

        Schema::table($new, function (Blueprint $table) use ($new, $follows): void {
            foreach (Schema::getForeignKeys($new) as $key) {
                $columns = implode('.', $key['columns']);

                if ($key['name'] !== null && $follows($key['name'], $columns, MakeMigrationCommand::FOREIGN_KEY_PREFIX)) {
                    $table->dropForeign($key['name']);
                    $table->foreign($key['columns'], MakeMigrationCommand::indexName($new, $columns, MakeMigrationCommand::FOREIGN_KEY_PREFIX))
                        ->references($key['foreign_columns'])->on($key['foreign_table'])->onDelete((string) $key['on_delete']);
                }
            }
        });

        Schema::table($new, function (Blueprint $table) use ($new, $follows): void {
            foreach (Schema::getIndexes($new) as $index) {
                $columns = implode('.', $index['columns']);

                if (! $index['primary'] && $follows($index['name'], $columns, MakeMigrationCommand::INDEX_PREFIX)) {
                    $table->renameIndex($index['name'], MakeMigrationCommand::indexName($new, $columns));
                }
            }
        });
    }

    private static function table(string $type): string
    {
        return "kami_type_{$type}";
    }

    /**
     * Each batch is a transaction, so an operation that stops leaves whole Entries behind and can run again.
     *
     * @param  Closure(array<array-key, string>): mixed  $callback
     */
    private static function eachBatch(string $type, Closure $callback): void
    {
        DB::table('kami_entries')->where('type', $type)->select('id')->chunkById(self::BATCH, function (Collection $entries) use ($callback): void {
            /** @var array<array-key, string> $ids */
            $ids = $entries->pluck('id')->all();
            DB::transaction(fn () => $callback($ids));
        });
    }
}
