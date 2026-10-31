<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Illuminate\Database\MySqlConnection;
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
     * MariaDB's `json` is a `longtext` and SQLite's a `text`: see `storesJson()`.
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
     * Whether a column an engine reports under another type is `json`, for the two that do (ADR 0006).
     *
     * Declared `json`, only while every value decodes, so a `Textarea` turned `Repeater` is still refused. Declared otherwise, MariaDB's `longtext` is one, as no Field declares that type, while SQLite's `text` is what it says: a `json` column turned `text` stays unseen there, its values reading as text all the same.
     */
    public static function storesJson(string $table, string $column, string $reported, ?string $declared): bool
    {
        $connection = DB::connection();
        $maria = $connection instanceof MySqlConnection && $connection->isMaria();

        if ($reported !== match (true) {
            $maria => 'longtext',
            $connection->getDriverName() === 'sqlite' => 'text',
            default => null,
        }) {
            return false;
        }

        if ($declared !== 'json') {
            return $maria;
        }

        return DB::table($table)->whereNotNull($column)->select($column)->cursor()->every(fn (object $row): bool => is_string($row->{$column}) && is_array(json_decode($row->{$column}, true)));
    }
}
