<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Sites\Internal\Site;

/**
 * Rewrites the Positions of an orderable type's Entries in the current Site. It does not authorise, and dispatches no event: no catalogue event covers it (ADR 0029).
 *
 * @internal
 */
final class ReorderEntries
{
    /**
     * The given Entries take the places they already held between them, in the given order; the others keep their rank. So a page of the table, or a search, reorders within what it shows. An id that is no sibling is ignored.
     *
     * @param  array<string>  $order
     */
    public function handle(ContentType $type, array $order): void
    {
        DB::transaction(function () use ($type, $order): void {
            /** @var array<string, int|string> $positions */
            $positions = self::siblings($type)->ordered()->toBase()->pluck('position', 'id')->all();
            $moved = array_values(array_intersect(array_unique($order), array_keys($positions)));
            $isMoved = array_flip($moved);
            $movedIndex = 0;
            $changes = [];

            foreach (array_keys($positions) as $index => $held) {
                $id = isset($isMoved[$held]) ? $moved[$movedIndex++] : $held;

                if ((int) $positions[$id] !== $index + 1) {
                    $changes[$id] = $index + 1;
                }
            }

            // ponytail: every row changes on the first reorder of a type whose Positions tie, and every id is read each time; #185's Volume suite measures it and bounds it if it exceeds a shared host's limits.
            foreach (array_chunk($changes, 500, true) as $chunk) {
                // The ids come from the query above; a binding could not sit in the update's expression.
                $cases = implode(' ', array_map(fn (string $id, int $position): string => 'when id = ' . DB::escape($id) . " then {$position}", array_keys($chunk), $chunk));

                // @phpstan-ignore argument.type (escaped ids and integers, built above)
                DB::table('kami_entries')->whereIn('id', array_keys($chunk))->update(['position' => new Expression("case {$cases} end")]);
            }
        });
    }

    /** The Position of a new last sibling. */
    public static function next(ContentType $type): int
    {
        /** @var int|string|null $last */
        $last = self::siblings($type)->toBase()->max('position');

        return (int) $last + 1;
    }

    private static function siblings(ContentType $type): EntryBuilder
    {
        return Entry::query()->withUnpublished()->ofType($type->handle())->where('site', Site::current()->handle);
    }
}
