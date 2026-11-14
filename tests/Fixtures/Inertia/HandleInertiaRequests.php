<?php

declare(strict_types=1);

namespace Psyao\Kami\Tests\Fixtures\Inertia;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Inertia\Middleware;
use Override;

/** A site's Inertia middleware, its asset version fixed, its root view this directory's `app.blade.php`. */
final class HandleInertiaRequests extends Middleware
{
    public const string VERSION = 'v1';

    #[Override]
    public function version(Request $request): string
    {
        return self::VERSION;
    }

    #[Override]
    public function rootView(Request $request): string
    {
        View::addNamespace('inertia-fixture', __DIR__);

        return 'inertia-fixture::app';
    }
}
