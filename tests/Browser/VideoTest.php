<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Internal\VideoFacade;
use Psyao\Kami\Media\Actions\SaveMedia;
use Workbench\App\Content\Article;

uses(RefreshDatabase::class);

beforeEach(function () {
    // From the skeleton's public directory, as on a Site after `kami:install`.
    VideoFacade::install();
    Storage::fake('local');
    $photo = resolve(SaveMedia::class)->handle(fixture('media/Holiday Photo.JPG'), ['alt' => 'Un carré rouge']);
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada', 'status' => 'published', 'body' => ['type' => 'doc', 'content' => [
        ['type' => 'block', 'attrs' => ['handle' => 'image', 'data' => ['image' => [$photo->id], 'caption' => 'Une photo']]],
        ['type' => 'block', 'attrs' => ['handle' => 'video', 'data' => ['url' => 'https://youtu.be/dQw4w9WgXcQ', 'caption' => 'Le clip']]],
        ['type' => 'block', 'attrs' => ['handle' => 'video', 'data' => ['url' => 'https://vimeo.com/76979871']]],
    ]]], locale: 'fr');
});

// Another suite's doctor would find it there and ask a server for it.
afterEach(fn () => File::deleteDirectory(public_path('vendor/kami')));

it('shows the Image and Video Blocks at axe\'s strictest level, light and dark', function () {
    // The image itself is broken here: Conversion URLs use the Site URL, not the test server's host.
    foreach ([visit('/articles/bonjour'), visit('/articles/bonjour')->inDarkMode()] as $page) {
        assertAxeClean($page->assertPresent('figure.kami-figure picture img[alt="Un carré rouge"]')->assertPresent('figure.kami-video a'))
            ->assertNoJavaScriptErrors();
    }
});

it('loads nothing from the provider until a click, then its privacy-preserving player in place of the link, under the strict CSP', function () {
    $provider = "performance.getEntriesByType('resource').some((entry) => /youtube|vimeo/.test(entry.name))";
    $page = visit('/articles/bonjour')
        ->assertScript("document.querySelectorAll('script[type=module][src$=\"/vendor/kami/video.js\"]').length", 1)
        ->assertScript($provider, false);
    $page->script("() => { window.violations = []; document.addEventListener('securitypolicyviolation', (event) => window.violations.push(event.violatedDirective)); }");

    $page->click('internal:role=link[name="Regarder « Le clip » sur YouTube"s]')
        ->assertPresent('iframe[src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1"][title="Le clip"][allowfullscreen]')
        ->assertMissing('a[href="https://www.youtube.com/watch?v=dQw4w9WgXcQ"]')
        ->assertScript('document.activeElement.tagName', 'IFRAME')
        // The other video waits for its own click.
        ->assertPresent('a[href="https://vimeo.com/76979871"]')
        ->assertScript("document.querySelectorAll('iframe').length", 1)
        ->assertScript('window.violations', [])
        ->assertNoJavaScriptErrors();
});
