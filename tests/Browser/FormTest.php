<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Forms\Submission;
use Workbench\App\Content\Home;

uses(RefreshDatabase::class);

it('shows the form, then its error summary after a refused Submission, at axe\'s strictest level, light and dark', function (bool $dark) {
    resolve(SaveEntry::class)->handle(Home::class, ['title' => 'Accueil', 'status' => 'published'], locale: 'fr');
    $page = $dark ? visit('/')->inDarkMode() : visit('/');

    assertAxeClean($page->assertPresent('form.kami-form'))
        ->fill('name', 'Ada Lovelace')
        ->fill('email', 'ada@example.com')
        ->fill('message', 'Bonjour')
        ->press('Envoyer')
        ->assertPresent('.kami-form-errors[role="alert"] a[href="#message"]')
        ->assertValue('name', 'Ada Lovelace');
    assertAxeClean($page)->assertNoJavaScriptErrors();
    expect(Submission::query()->count())->toBe(0);
})->with(['light' => false, 'dark' => true]);

it('submits without a reload through the guide\'s script, from a cached page under the strict front policy', function () {
    // The page cache stores a page of the Site's host only: the browser's.
    config(['kami.page_cache.enabled' => true, 'kami.sites.default.url' => 'http://127.0.0.1']);
    $entry = resolve(SaveEntry::class)->handle(Home::class, ['title' => 'Accueil', 'status' => 'published'], locale: 'fr');
    visit('/')->assertSee('Accueil');
    // Changed behind Kami's back, so a page that still shows the former title comes from the page cache.
    DB::table('kami_translations')->where('entry_id', $entry->id)->update(['title' => 'Changed']);

    $page = visit('/')->assertSee('Accueil');
    // A reload would drop this marker; the button's state is read as the request leaves.
    $page->script("() => { window.notReloaded = true; const send = window.fetch; window.fetch = (...args) => { window.disabledWhileSending = document.querySelector('.kami-form [type=submit]').disabled; return send(...args); }; }");

    $page->fill('name', 'Ada Lovelace')
        ->fill('email', 'ada@example.com')
        ->fill('message', 'Bonjour')
        ->press('Envoyer')
        ->assertPresent('.kami-form-errors[role="alert"] a[href="#message"]')
        ->assertScript("document.activeElement.classList.contains('kami-form-errors')", true)
        ->assertAttribute('#message', 'aria-invalid', 'true')
        ->assertAttribute('#message', 'aria-describedby', 'message-error')
        ->assertScript('window.disabledWhileSending', true)
        ->assertScript("document.querySelector('.kami-form [type=submit]').disabled", false);
    assertAxeClean($page);

    $page->fill('message', 'Bonjour, parlons de votre projet.')
        ->press('Envoyer')
        ->assertSeeIn('#contact-status', 'Merci, votre message est envoyé.')
        ->assertScript("document.activeElement.id === 'contact-status' && document.querySelector('form.kami-form').hidden", true)
        ->assertMissing('.kami-form-errors')
        ->assertScript('window.notReloaded', true)
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
    expect(Submission::query()->count())->toBe(1);
});
