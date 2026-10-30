<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Illuminate\Support\Facades\DB;

/**
 * What the engines report for the column types a Field declares, so a declaration and a table compare on all four (ADR 0006).
 *
 * @internal
 */
final class ColumnTypes
{
    /**
     * `Schema::getColumns()`'s full `type` first: `tinyint(1)` is a boolean on MySQL, MariaDB and SQLite, any other `tinyint` is not;
     * PostgreSQL's `dateTime` is `timestamp(0) without time zone`, while a `timestamp` column on MySQL and MariaDB is not one, since dates are never stored in it (ADR 0004);
     * MariaDB's `json` is a `longtext`, a type no Field declares otherwise. SQLite's is a `text`: see `holdsJson()`.
     */
    public const array DECLARED = [
        'varchar' => 'string',
        'text' => 'text',
        'bool' => 'boolean',
        'tinyint(1)' => 'boolean',
        'int' => 'integer',
        'int4' => 'integer',
        'integer' => 'integer',
        'decimal' => 'decimal',
        'numeric' => 'decimal',
        'date' => 'date',
        'datetime' => 'dateTime',
        'timestamp(0) without time zone' => 'dateTime',
        'json' => 'json',
        'longtext' => 'json',
    ];

    /**
     * The column type a Field would declare for a column, or the engine's name for it when no Field would.
     *
     * @param  array{type: string, type_name: string}  $column
     */
    public static function of(array $column): string
    {
        return self::DECLARED[$column['type']] ?? self::DECLARED[$column['type_name']] ?? $column['type_name'];
    }

    /**
     * Whether a `text` column may be a declared `json` one, which SQLite also reports as `text`: only there, and only while every value decodes, so a `Textarea` turned `Repeater` is still refused.
     *
     * A `json` column turned `text` stays unseen on SQLite: its values read as text all the same.
     */
    public static function holdsJson(string $table, string $column): bool
    {
        return DB::getDriverName() === 'sqlite'
            && DB::table($table)->whereNotNull($column)->select($column)->cursor()->every(fn (object $row): bool => is_string($row->{$column}) && is_array(json_decode($row->{$column}, true)));
    }
}
