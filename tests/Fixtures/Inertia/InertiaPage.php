<?php

declare(strict_types=1);

namespace Psyao\Kami\Tests\Fixtures\Inertia;

use Inertia\Inertia;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Symfony\Component\HttpFoundation\Response;

/** A type with no Field rendered as an Inertia page, as a site renders one behind HandleInertiaRequests. */
final class InertiaPage extends ContentType
{
    public function render(Entry $entry): Response
    {
        return Inertia::render('Page', ['title' => $entry->title])->toResponse(request());
    }
}
