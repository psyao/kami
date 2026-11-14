<?php

declare(strict_types=1);

namespace Psyao\Kami\Routing\Internal;

use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Collection;
use Override;
use Psyao\Kami\Content\Entry;

/**
 * A listing's page whose Entries under a hidden ancestor were dropped, its cursors kept from the page as read:
 * a page left empty still links on, and a dropped last Entry is not read again (ADR 0019).
 *
 * @internal
 *
 * @extends CursorPaginator<int, Entry>
 */
final class ListingPage extends CursorPaginator
{
    /**
     * @param  Collection<int, Entry>  $entries  In the page's order, already sliced and turned.
     * @param  array<string, mixed>  $options
     */
    public function __construct(Collection $entries, int $perPage, ?Cursor $cursor, array $options, private readonly ?Cursor $previous, private readonly ?Cursor $next)
    {
        // No cursor here: the parent would turn a backward page's Entries a second time.
        parent::__construct($entries, $perPage, null, $options);
        $this->cursor = $cursor;
    }

    #[Override]
    public function previousCursor(): ?Cursor
    {
        return $this->previous;
    }

    #[Override]
    public function nextCursor(): ?Cursor
    {
        return $this->next;
    }

    #[Override]
    public function hasMorePages(): bool
    {
        return $this->next instanceof Cursor;
    }

    #[Override]
    public function onFirstPage(): bool
    {
        return ! $this->previous instanceof Cursor;
    }
}
