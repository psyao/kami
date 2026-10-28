<?php

declare(strict_types=1);

use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Facades\Kami;
use Workbench\App\Content\Article;

uses(RefreshDatabase::class);

/** ADR 0018's front policy, word for word. */
const FRONT_POLICY = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; media-src 'self'; "
    . 'frame-src https://www.youtube-nocookie.com https://player.vimeo.com; '
    . "object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; upgrade-insecure-requests";

const FRONT_HEADERS = [
    'X-Content-Type-Options' => 'nosniff',
    'Referrer-Policy' => 'strict-origin-when-cross-origin',
    'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), browsing-topics=()',
    'Cross-Origin-Opener-Policy' => 'same-origin',
    'X-Frame-Options' => 'DENY',
];

beforeEach(function () {
    Kami::routes();

    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Hello world', 'status' => 'published', 'author' => 'Ada Lovelace']);
});

// The Workbench appends SecurityHeaders to `web`, which Kami's route leaves out: the page keeps its own.
it('sends the front policy and its companion headers on a page and on a 404', function (string $path, int $status) {
    $response = $this->get($path)->assertStatus($status)
        ->assertHeader('Content-Security-Policy', FRONT_POLICY)
        ->assertHeaderMissing('Content-Security-Policy-Report-Only')
        ->assertHeaderMissing('X-Powered-By');

    foreach (FRONT_HEADERS as $name => $value) {
        $response->assertHeader($name, $value);
    }
})->with([
    'page' => ['/articles/hello-world', 200],
    'missing' => ['/articles/nowhere', 404],
]);

it('merges the sources a site adds, keeping every default', function () {
    config(['kami.security.csp' => ['img-src' => ['https://cdn.example.com'], 'object-src' => ['https://embed.example.com'], 'manifest-src' => ["'self'"]]]);

    $policy = $this->get('/articles/hello-world')->headers->get('Content-Security-Policy');

    expect($policy)->toContain("img-src 'self' data: https://cdn.example.com;")
        ->toContain('object-src https://embed.example.com;')
        ->toContain("script-src 'self';")
        ->toEndWith("upgrade-insecure-requests; manifest-src 'self'");
});

it('replaces a companion header, or omits it when set to null', function () {
    config(['kami.security.headers' => ['X-Frame-Options' => null, 'Referrer-Policy' => 'no-referrer']]);

    $this->get('/articles/hello-world')
        ->assertHeaderMissing('X-Frame-Options')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('reports violations to the configured address, and only reports them in report-only mode', function () {
    config(['kami.security.csp_report_uri' => 'https://reports.example.com/csp', 'kami.security.csp_report_only' => true]);

    $this->get('/articles/hello-world')
        ->assertHeaderMissing('Content-Security-Policy')
        ->assertHeader('Content-Security-Policy-Report-Only', FRONT_POLICY . '; report-uri https://reports.example.com/csp');
});

it('adds the Vite dev server while it runs hot', function () {
    // Not public/hot itself: tests running in parallel would see it.
    File::put($hot = sys_get_temp_dir() . '/kami-hot-' . uniqid(), "http://[::1]:5173\n");
    Vite::useHotFile($hot);

    try {
        $policy = $this->get('/articles/hello-world')->headers->get('Content-Security-Policy');
    } finally {
        File::delete($hot);
    }

    expect($policy)->toContain("script-src 'self' http://[::1]:5173;")
        ->toContain("style-src 'self' http://[::1]:5173;")
        ->toContain("connect-src 'self' http://[::1]:5173 ws://[::1]:5173;");
});

it('sends the admin policy with a nonce that signs every inline script of the panel', function () {
    $response = $this->get('/admin/login')->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'same-origin')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeaderMissing('Permissions-Policy');

    $policy = (string) $response->headers->get('Content-Security-Policy');
    expect(preg_match("/'nonce-([^']+)'/", $policy, $match))->toBe(1);

    expect(str_replace($match[0], "'nonce-…'", $policy))->toBe(
        "default-src 'self'; script-src 'self' 'nonce-…' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; worker-src 'self' blob:; "
        . "object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'",
    );

    $page = HTMLDocument::createFromString((string) $response->getContent(), LIBXML_NOERROR);
    $inline = collect($page->querySelectorAll('script:not([src])'));

    expect($inline)->not->toBeEmpty()
        ->and($inline->map(fn (Element $script) => $script->getAttribute('nonce'))->unique()->all())->toBe([$match[1]]);
});

it('draws a new nonce for every admin request', function () {
    $nonce = fn () => $this->get('/admin/login')->headers->get('Content-Security-Policy');

    expect($nonce())->not->toBe($nonce());
});
