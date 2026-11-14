<?php

declare(strict_types=1);

namespace Psyao\Kami\Tests\Fixtures\Inertia;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Inertia\Inertia;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Symfony\Component\HttpFoundation\Response;

/** A type with no Field, and its Archive, rendered as Inertia pages, as a site renders one behind HandleInertiaRequests. */
final class InertiaPage extends ContentType
{
    #[\Override]
    protected bool $archive = true;

    public function render(Entry $entry): Response
    {
        return Inertia::render('Page', ['title' => $entry->title])->toResponse(request());
    }

    #[\Override]
    public function renderArchive(CursorPaginator $entries): Response
    {
        return Inertia::render('Archive', ['titles' => collect($entries->items())->map(fn (Entry $entry): ?string => $entry->title)->all()])->toResponse(request());
    }
}
