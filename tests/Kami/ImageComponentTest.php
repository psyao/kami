<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\ViewException;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Media\Actions\SaveMedia;
use Psyao\Kami\Media\ImagePreset;
use Psyao\Kami\Media\Internal\ImageFormats;
use Psyao\Kami\Media\Media;
use Psyao\Kami\Sites\Internal\Site;
use Workbench\App\Content\Project;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
    // The fallback format only, unless a test asks for more: what this host encodes is not the test's subject.
    failingToEncode('avif', 'webp');
    config(['kami.media.presets' => [
        ImagePreset::make('card')->ratio(4, 3)->widths([20, 40, 80])->sizes('(min-width: 60rem) 33vw, 100vw'),
        ImagePreset::make('content')->widths([20, 80]),
        ImagePreset::make('hero')->widths([80, 160]),
    ]]);
});

afterEach(function (): void {
    failingToEncode();
});

/** Shown 40 × 60 once oriented. */
function photo(array $values = []): Media
{
    return resolve(SaveMedia::class)->handle(fixture('media/rotated-gps.jpg'), $values);
}

function renderImage(?Media $media, string $attributes = 'preset="card"'): string
{
    return trim((string) preg_replace('/>\s+</', '><', Blade::render("<x-kami::image :media=\"\$media\" {$attributes} />", ['media' => $media])));
}

it('renders a picture whose srcset stops at the original\'s width, sized from the preset\'s ratio', function (): void {
    $media = photo(['alt' => 'A red and blue flag']);

    expect(renderImage($media))->toBe('<picture class="kami-image">'
        . '<img src="' . $media->url('card', 40) . '"'
        . ' srcset="' . $media->url('card', 20) . ' 20w, ' . $media->url('card', 40) . ' 40w"'
        . ' sizes="(min-width: 60rem) 33vw, 100vw" width="40" height="30" alt="A red and blue flag" loading="lazy" decoding="async">'
        . '</picture>');
});

it('keeps the image\'s own proportions without a ratio, and offers the smallest width alone, at the original\'s, when none fits', function (string $preset, string $srcset, string $size): void {
    $media = photo();
    $urls = ['content-20' => $media->url('content', 20), 'hero-80' => $media->url('hero', 80)];

    expect(renderImage($media, "preset=\"{$preset}\""))
        ->toContain(' srcset="' . strtr($srcset, $urls) . '"')
        ->toContain($size)
        ->not->toContain('sizes=');
})->with([
    'a width that fits' => ['content', 'content-20 20w', 'width="20" height="30"'],
    'none that fits' => ['hero', 'hero-80 40w', 'width="40" height="60"'],
]);

it('stops at the width a ratio crops a wider original to', function (): void {
    // 20 × 10, cropped to 13 × 10 in 4:3: every declared width gives a file 13 pixels wide.
    $media = resolve(SaveMedia::class)->handle(fixture('media/pixel.png'));

    expect(renderImage($media))->toContain(' srcset="' . $media->url('card', 20) . ' 13w" sizes="(min-width: 60rem) 33vw, 100vw" width="13" height="10"');
});

it('offers AVIF before WebP where this host encodes it, unless kami.media.avif is off', function (): void {
    failingToEncode();
    $media = photo();
    $offered = renderImage($media);

    if (! str_contains($offered, 'image/avif')) {
        $this->markTestSkipped('No Image driver encodes AVIF on this host.');
    }

    expect($offered)->toContain('<picture class="kami-image"><source type="image/avif" srcset="' . $media->url('card', 20, 'avif') . ' 20w, ' . $media->url('card', 40, 'avif') . ' 40w" sizes="(min-width: 60rem) 33vw, 100vw"><source type="image/webp"');

    config(['kami.media.avif' => false]);

    expect(renderImage($media))->not->toContain('image/avif')->toContain('image/webp');
});

it('offers AVIF and WebP before the fallback format, each only where this host encodes it', function (): void {
    failingToEncode('avif');
    $media = photo();
    $offered = renderImage($media);

    if (! str_contains($offered, 'image/webp')) {
        // CI's images encode WebP at least: there, a missing one fails rather than skips.
        getenv('CI') === false ? $this->markTestSkipped('No Image driver encodes WebP on this host.') : $this->fail('No Image driver encodes WebP on this CI image.');
    }

    expect($offered)
        ->toContain('<picture class="kami-image"><source type="image/webp" srcset="' . $media->url('card', 20, 'webp') . ' 20w, ' . $media->url('card', 40, 'webp') . ' 40w" sizes="(min-width: 60rem) 33vw, 100vw"><img src="' . $media->url('card', 40, 'jpg') . '"')
        ->not->toContain('image/avif');
});

it('takes sizes from the component over the preset\'s, and loads an eager image at once with a high priority', function (): void {
    $markup = renderImage(photo(), 'preset="card" sizes="50vw" eager class="hero" id="cover"');

    expect($markup)
        ->toContain(' sizes="50vw"')
        ->toContain(' fetchpriority="high"')
        ->toContain(' decoding="async"')
        ->toContain('class="hero"')
        ->toContain('id="cover"')
        ->not->toContain('loading=')
        ->not->toContain('33vw');
});

it('reads the alt text in the current Locale, marking with lang the default Locale\'s it falls back to', function (): void {
    config(['kami.sites.default.locales' => ['en', 'fr', 'de']]);
    $media = photo(['alt' => 'A flag']);
    resolve(SaveMedia::class)->handle(null, ['alt' => 'Un drapeau'], $media, 'fr');
    $media = Media::query()->findOrFail($media->id);

    Site::useLocale('fr');
    expect(renderImage($media))->toContain(' alt="Un drapeau"')->not->toContain('lang=');

    Site::useLocale('de');
    expect(renderImage($media))->toContain(' alt="A flag" lang="en"');

    Site::useLocale('en');
    expect(renderImage($media))->toContain(' alt="A flag"')->not->toContain('lang=');
});

it('renders an empty alt for a decorative image and for one missing its alt text, never its filename', function (array $values): void {
    expect(renderImage(photo($values)))->toContain(' alt=""')->not->toContain('rotated');
})->with([
    'decorative' => [['alt' => 'A flag', 'decorative' => true]],
    'missing' => [[]],
]);

it('escapes the alt text', function (): void {
    expect(renderImage(photo(['alt' => '"><script>alert(1)</script>'])))
        ->toContain(' alt="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"')
        ->not->toContain('<script>');
});

it('renders nothing for a null Media item, a document, or an SVG until it has a URL of its own', function (?Closure $media): void {
    expect(renderImage($media?->__invoke()))->toBe('');
})->with([
    'null' => [null],
    'a document' => [fn (): Media => resolve(SaveMedia::class)->handle(fixture('media/brochure.pdf'))],
    'an SVG' => [fn (): Media => resolve(SaveMedia::class)->handle(UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 80"/>'))],
]);

it('refuses a preset not declared', function (): void {
    expect(fn (): string => renderImage(photo(), 'preset="banner"'))->toThrow(ViewException::class, 'No Image Preset [banner] is declared.');
});

it('renders a listing\'s covers with no query once the Entries are loaded with them', function (): void {
    foreach (['First', 'Second', 'Third'] as $title) {
        resolve(SaveEntry::class)->handle(Project::class, ['title' => $title, 'status' => 'published', 'cover' => photo(['alt' => $title])->id], locale: 'fr');
    }

    Model::shouldBeStrict();
    // Shared hosting's store, where every cache read is a query. The formats are probed before the page is counted.
    config(['cache.default' => 'database']);
    resolve(ImageFormats::class)->available();
    DB::enableQueryLog();
    $entries = Entry::query()->ofType('project')->with('cover')->get();
    $loading = count(DB::getQueryLog());

    $markup = Blade::render('@foreach ($entries as $entry)<x-kami::image :media="$entry->cover" preset="card" />@endforeach', ['entries' => $entries]);

    // Entries, their Translations, their Field values, references, Media items, their Translations; while rendering, the
    // formats' one cache read, whatever the number of images.
    expect($loading)->toBe(6)
        ->and(DB::getQueryLog())->toHaveCount(7)
        ->and(substr_count($markup, '<picture class="kami-image">'))->toBe(3);
});
