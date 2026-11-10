<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('renders the 5xx pages at axe\'s strictest level, every resource they load found', function (int $status): void {
    // No Workbench route fails on purpose: the test declares one, served from its own application.
    Route::get('/fails', fn () => abort($status));

    assertAxeClean(visit('/fails')->assertSee((string) $status))
        // A missing stylesheet logs no console message: its response status is the only trace (#508).
        ->assertScript("performance.getEntriesByType('resource').every((entry) => entry.responseStatus < 400)")
        ->assertNoJavaScriptErrors();
})->with([500, 503]);
