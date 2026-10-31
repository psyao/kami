<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Illuminate\Support\Facades\DB;

/**
 * The walk up a tree, one primary key read per level: trees stay shallow, and it asks nothing of the engine.
 *
 * @internal
 */
final class Ancestors
{
    /**
     * The given Parent's id and its own Parents', nearest first; stops at a cycle concurrent moves could close.
     *
     * @return list<string>
     */
    public static function ids(?string $parent): array
    {
        $ids = [];

        while ($parent !== null && ! in_array($parent, $ids, true)) {
            $ids[] = $parent;
            /** @var string|null $parent */
            $parent = DB::table('kami_entries')->where('id', $parent)->value('parent_id');
        }

        return $ids;
    }
}
