<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

/**
 * What the engines report for the column types a Field declares, so a declaration and a table compare on all four (ADR 0006).
 *
 * @internal
 */
final class ColumnTypes
{
    /**
     * `Schema::getColumns()`'s full `type` first: `tinyint(1)` is a boolean on MySQL, MariaDB and SQLite, any other `tinyint` is not;
     * PostgreSQL's `dateTime` is `timestamp(0) without time zone`, while a `timestamp` column on MySQL and MariaDB is not one, since dates are never stored in it (ADR 0004).
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
}
