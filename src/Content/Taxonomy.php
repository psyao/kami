<?php

declare(strict_types=1);

namespace Psyao\Kami\Content;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\View\View;
use Override;
use Symfony\Component\HttpFoundation\Response;

/** A Content Type classifying others: its Entries are `Term`s, published by default, linked with the `Terms` Field (ADR 0002). */
abstract class Taxonomy extends ContentType
{
    #[Override]
    protected ?string $model = Term::class;

    /**
     * Turns a visible Term into the response at its Path, its Term Archive: Kami also passes it as `$term`, and `$entries`, the visible Entries of routable types linking it, newest first (ADR 0009).
     *
     * @param  CursorPaginator<int, Entry>|null  $entries
     */
    #[Override]
    public function render(Entry $entry, ?CursorPaginator $entries = null): View | Response
    {
        return view()->make("kami.{$this->handle()}", ['entry' => $entry, 'term' => $entry, 'entries' => $entries]);
    }
}
