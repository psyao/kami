<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Component;
use Livewire\Livewire;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Facades\Kami;
use Symfony\Component\HttpFoundation\Response;
use Workbench\App\Content\Home;

uses(RefreshDatabase::class);

/** A type whose page holds a site's own Livewire component, with an Alpine expression of its own. */
final class LikesPage extends ContentType
{
    public function render(Entry $entry): Response
    {
        return response(Blade::render('<!DOCTYPE html><html lang="fr"><head><title>Likes</title></head><body><main><livewire:likes /></main></body></html>'));
    }
}

final class Likes extends Component
{
    public int $count = 0;

    public function like(): void
    {
        $this->count++;
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div x-data="{ thanked: false }">
                <button type="button" wire:click="like" x-on:click="thanked = true">J'aime</button>
                <output id="likes">{{ $count }}</output>
                <p id="thanks" x-show="thanked">Merci</p>
                <span id="sending" wire:loading>Envoi…</span>
            </div>
            BLADE;
    }
}

it('runs a site\'s Livewire component and its Alpine on Kami\'s pages, cached or in a session, under the strict front policy, with no violation', function (bool $session) {
    // A `storage/` of the test's own, so no earlier run's cached page answers.
    $this->app->useStoragePath(temporaryPath());
    config(['kami.types' => [Home::class, LikesPage::class]]);
    Livewire::component('likes', Likes::class);
    if ($session) {
        Kami::routes(session: true);
    }
    resolve(SaveEntry::class)->handle(LikesPage::class, ['title' => 'Likes', 'status' => 'published'], locale: 'fr');
    // The guide's second condition: Livewire's inline style, allowed by its hash.
    preg_match('#<style >(.*?)</style>#s', (string) $this->get('/likes_page/likes')->getContent(), $style);
    config(['kami.security.csp' => ['style-src' => ["'sha256-" . base64_encode(hash('sha256', $style[1], true)) . "'"]]]);
    // From now, with that policy; the page cache stores a page of the Site's host only: the browser's.
    config(['kami.page_cache.enabled' => true, 'kami.sites.default.url' => 'http://127.0.0.1']);
    Livewire::flushState();

    if (! $session) {
        // Stored by this visit, so the next comes from the page cache; Livewire injects its assets once per container, as the browser's server keeps it.
        visit('/likes_page/likes')->assertSee('J\'aime');
    }

    // Hidden by Livewire's inline style alone: a blocked style would show it, and the browser reports nothing to the page before it loads.
    $page = visit('/likes_page/likes')->assertSeeIn('#likes', '0')->assertMissing('#thanks:visible')->assertMissing('#sending:visible');
    $page->script("() => { window.violations = []; document.addEventListener('securitypolicyviolation', (event) => window.violations.push(event.violatedDirective)); }");

    $page->press('J\'aime')
        ->assertSeeIn('#likes', '1')
        ->assertVisible('#thanks')
        ->assertScript('window.violations', [])
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
})->with(['the default mount' => false, 'the session mount' => true]);
