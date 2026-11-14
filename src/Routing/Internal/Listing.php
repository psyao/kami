<?php

declare(strict_types=1);

namespace Psyao\Kami\Routing\Internal;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Internal\EntryBuilder;
use Psyao\Kami\Content\Internal\Translation;

/**
 * A page of an Archive or a Term Archive: visible Entries, newest first or in Position order, one cursor-paginated query whatever the volume (ADR 0008).
 *
 * @internal
 */
final class Listing
{
    /** The query string key the cursor travels in. */
    public const string CURSOR = 'cursor';

    /** The alias the publication date is read under: two underscores, which no Field handle takes. */
    private const string PUBLISHED_AT = 'kami__published_at';

    /**
     * Answers 404 for a malformed cursor, or an empty page other than the first. An Entry under an ancestor not visible in the Locale is dropped
     * from its page, which then holds fewer Entries: no query of fixed cost can see ancestors (ADR 0019).
     *
     * @param  EntryBuilder  $query  The Entries to list, in the Locale they are read in.
     * @param  string  $url  The page's address, which the cursor links append to.
     * @return CursorPaginator<int, Entry>
     */
    public static function page(EntryBuilder $query, bool $byPosition, int $perPage, string $url, mixed $cursor = null): CursorPaginator
    {
        $query->select('kami_entries.*');

        if ($byPosition) {
            $query->orderBy('kami_entries.position')->orderBy('kami_entries.id');
            $parameters = ['kami_entries.position', 'kami_entries.id'];
        } else {
            $locale = $query->locale();
            // The Translation read in the Locale: at most one per Entry, its own or the `'*'` one of an untranslatable type.
            $query->join('kami_translations', fn (JoinClause $translation): JoinClause => $translation->on('kami_translations.entry_id', '=', 'kami_entries.id')
                ->whereIn('kami_translations.locale', [$locale, '*'])
                ->where('kami_translations.status', Translation::PUBLISHED)
                ->where('kami_translations.published_at', '<=', now()))
                ->addSelect('kami_translations.published_at as ' . self::PUBLISHED_AT)
                // On the base query: the alias is no attribute of Entry.
                ->getQuery()->orderByDesc(self::PUBLISHED_AT)->orderByDesc('kami_entries.id');
            $parameters = [self::PUBLISHED_AT, 'kami_entries.id'];
        }

        $decoded = is_string($cursor) ? Cursor::fromEncoded($cursor) : null;
        abort_if($cursor !== null && ! self::fits($decoded, $parameters), 404);

        /** @var CursorPaginator<int, Entry> $page */
        $page = $query->cursorPaginate($perPage, ['*'], self::CURSOR, $decoded);
        abort_if($decoded !== null && $page->isEmpty(), 404);

        VisiblePaths::preload($page->items());

        return $page->setCollection($page->getCollection()->filter(fn (Entry $entry): bool => $entry->url() !== null)->values())->withPath($url);
    }

    /**
     * A cursor this listing gave: its order's parameters, scalar, and a direction.
     *
     * @param  list<string>  $parameters
     */
    private static function fits(?Cursor $cursor, array $parameters): bool
    {
        if (! $cursor instanceof Cursor) {
            return false;
        }

        $values = $cursor->toArray();
        $direction = $values['_pointsToNextItems'];
        unset($values['_pointsToNextItems']);

        return is_bool($direction) && array_keys($values) === $parameters && array_filter($values, fn (mixed $value): bool => ! is_string($value) && ! is_int($value)) === [];
    }
}
