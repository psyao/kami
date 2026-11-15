<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psyao\Kami\Redirects\Actions\SaveRedirect;

uses(RefreshDatabase::class);

it('lists the Redirects and opens their form, the page and its modal clean under axe', function (): void {
    resolve(SaveRedirect::class)->handle('/blog', 301, '/articles');
    resolve(SaveRedirect::class)->handle('/?attachment_id', 410);
    // As Kami wrote one before 0.10.0: no date.
    DB::table('kami_paths')->insert(['id' => strtolower((string) Str::ulid()), 'site' => 'default', 'locale' => 'fr', 'path' => 'ancienne', 'target' => '/articles', 'status' => 301]);

    $page = assertAxeClean(signIn()->navigate('/admin/redirects')->assertSee('/blog')->assertSee('410: gone')->assertSee('Unknown'), admin: true);

    $page->click('internal:role=button[name="New redirect"i]')->assertSee('The address visitors arrive at');
    // Once the modal's opening transition ends: axe would read the page behind it half-faded.
    $page->wait(1);
    assertAxeClean($page, admin: true);

    // The edit modal: the source and Locale disabled, their help changed.
    $page->navigate('/admin/redirects')->click('internal:role=button[name="Edit"i] >> nth=0')->assertSee('To change the source, delete this Redirect');
    $page->wait(1);
    assertAxeClean($page, admin: true)
        ->assertNoJavaScriptErrors();
});
