<?php

declare(strict_types=1);

namespace Psyao\Kami\Tests\Fixtures\Inertia;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Override;

/** A site's Inertia middleware, its asset version fixed, its root view this directory's `app.blade.php`, registered by `inertiaPage()`. */
final class HandleInertiaRequests extends Middleware
{
    public const string VERSION = 'v1';

    /** @var string */
    protected $rootView = 'inertia-fixture::app';

    #[Override]
    public function version(Request $request): string
    {
        return self::VERSION;
    }
}
