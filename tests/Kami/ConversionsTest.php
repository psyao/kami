<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Image\ImageException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Media\Actions\SaveMedia;
use Psyao\Kami\Media\ImagePreset;
use Psyao\Kami\Media\Internal\Conversion;
use Psyao\Kami\Media\Internal\ImageFormats;
use Psyao\Kami\Media\Media;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
    Kami::routes();
    config(['kami.media.presets' => [ImagePreset::make('card')->ratio(4, 3)->widths([20, 40])->sizes('100vw')]]);
});

/** Stored 60 × 40, red on the left and blue on the right, with EXIF Orientation 6: shown 40 × 60, red on top. With a camera make and GPS. */
function rotatedPhoto(): Media
{
    return resolve(SaveMedia::class)->handle(fixture('media/rotated-gps.jpg'));
}

/** The response's image, decoded, with its bytes. */
function served(TestResponse $response): GdImage
{
    $response->assertOk();

    return imagecreatefromstring((string) file_get_contents($response->baseResponse->getFile()->getPathname()))
        ?: throw new RuntimeException('The response is not an image.');
}

/** Whether a pixel is mostly red rather than blue. */
function isRed(GdImage $image, int $x, int $y): bool
{
    $rgb = imagecolorat($image, $x, $y);

    return ($rgb >> 16 & 0xFF) > ($rgb & 0xFF);
}

/** A Conversion's URL with one of its segments replaced. */
function tampered(string $url, int $segment, string $value): string
{
    $parts = explode('/', $url);
    $parts[$segment] = $value;

    return implode('/', $parts);
}

it('generates a Conversion on its first request, writes it, then serves the file on disk', function (): void {
    $url = rotatedPhoto()->url('card', 40);
    $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');

    $this->get($url)
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeaderMissing('Set-Cookie');
    Storage::disk('public')->assertExists($path);

    Storage::disk('public')->put($path, 'on disk');
    expect(file_get_contents($this->get($url)->baseResponse->getFile()->getPathname()))->toBe('on disk');
});

it('writes nothing for a Media item whose file was replaced since it was read, so the former picture never comes back', function (): void {
    $read = resolve(SaveMedia::class)->handle(fixture('media/Holiday Photo.JPG'));
    $stale = Conversion::find($read, 'original', 64, 'jpg');
    resolve(SaveMedia::class)->handle(fixture('media/pixel.png'), media: Media::query()->findOrFail($read->id));

    expect($stale->write())->toBeNull()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('builds Conversion URLs under the Site URL, defaulting to the largest width in the fallback format', function (): void {
    $media = rotatedPhoto();

    expect($media->url('card'))->toBe($media->url('card', 40, 'jpg'))
        ->and($media->url('card'))->toMatch("~^http://localhost/media/{$media->id}/[0-9a-f]{8}/card-40\\.jpg$~")
        ->and($media->url())->toEndWith('/original-40.jpg')
        ->and(resolve(SaveMedia::class)->handle(fixture('media/pixel.png'))->url())->toEndWith('/original-20.png');
});

it('refuses to build the URL of a Conversion not declared', function (?string $preset, ?int $width, ?string $format, string $message): void {
    expect(fn () => rotatedPhoto()->url($preset, $width, $format))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'preset' => ['banner', null, null, 'No Image Preset [banner] is declared.'],
    'width' => ['card', 30, null, 'has no Conversion'],
    'format' => ['card', 40, 'png', 'has no Conversion'],
]);

it('orients, strips the metadata, crops around the centre and never upscales', function (string $driver): void {
    if (! extension_loaded($driver)) {
        $this->markTestSkipped("{$driver} is not loaded.");
    }

    config(['images.default' => $driver, 'kami.media.presets' => [
        ImagePreset::make('square')->ratio(1, 1)->widths([20]),
        ImagePreset::make('large')->widths([400]),
    ]]);
    $media = rotatedPhoto();

    $original = served($this->get($media->url()));
    expect([imagesx($original), imagesy($original)])->toBe([40, 60])
        ->and(isRed($original, 20, 5))->toBeTrue()
        ->and(isRed($original, 20, 55))->toBeFalse();

    $bytes = (string) file_get_contents($this->get($media->url())->baseResponse->getFile()->getPathname());
    expect($bytes)->not->toContain("Exif\0\0")->not->toContain('KamiTestCam');

    // The middle 40 × 40 of the shown 40 × 60: red on top, blue below.
    $square = served($this->get($media->url('square')));
    expect([imagesx($square), imagesy($square)])->toBe([20, 20])
        ->and(isRed($square, 10, 2))->toBeTrue()
        ->and(isRed($square, 10, 17))->toBeFalse();

    $large = served($this->get($media->url('large')));
    expect([imagesx($large), imagesy($large)])->toBe([40, 60]);
})->with(['gd', 'imagick']);

it('serves AVIF and WebP where this host encodes them, in that format', function (string $format): void {
    if (! array_key_exists($format, resolve(ImageFormats::class)->available())) {
        // CI's images encode WebP at least: there, a missing one fails rather than skips.
        if ($format === 'webp' && getenv('CI') !== false) {
            $this->fail('No Image driver encodes WebP on this CI image.');
        }

        $this->markTestSkipped("No Image driver encodes {$format} on this host.");
    }

    $response = $this->get(rotatedPhoto()->url('card', 40, $format))->assertOk()->assertHeader('Content-Type', "image/{$format}");

    expect(getimagesize($response->baseResponse->getFile()->getPathname()))->toMatchArray([0 => 40, 1 => 30, 'mime' => "image/{$format}"]);
})->with(['avif', 'webp']);

it('answers 404 and generates nothing for a format this host fails to encode, or kami.media.avif switches off', function (string $format, Closure $unavailable): void {
    $unavailable();
    $media = rotatedPhoto();
    $url = str_replace('.jpg', ".{$format}", $media->url('card', 40));

    $this->get($url)->assertNotFound();

    expect(Storage::disk('public')->allFiles())->toBe([])
        ->and(fn () => $media->url('card', 40, $format))->toThrow(InvalidArgumentException::class, 'has no Conversion');
})->with([
    'WebP failing its encode test' => ['webp', fn () => failingToEncode('webp')],
    'AVIF switched off' => ['avif', fn () => config(['kami.media.avif' => false])],
]);

it('offers kami-social in the fallback format only', function (): void {
    $media = rotatedPhoto();

    expect(fn () => $media->url('kami-social', 1200, 'webp'))->toThrow(InvalidArgumentException::class, 'has no Conversion')
        ->and($media->url('kami-social'))->toEndWith('/kami-social-1200.jpg');
});

it('answers 302 to the fallback format at the same width when a generation fails, logs it, writes nothing, and retries later', function (): void {
    $format = array_key_first(resolve(ImageFormats::class)->available()) ?? $this->markTestSkipped('No Image driver encodes AVIF or WebP on this host.');
    Exceptions::fake();
    $media = rotatedPhoto();
    $url = $media->url('card', 40, $format);
    failingToEncode($format);

    $response = $this->get($url)->assertRedirect($media->url('card', 40));

    // Symfony sorts the directives: compare them one by one.
    expect(explode(', ', (string) $response->headers->get('Cache-Control')))->not->toContain('immutable')->toContain('no-cache')
        ->and(Storage::disk('public')->allFiles())->toBe([]);
    Exceptions::assertReported(ImageException::class);

    failingToEncode();
    $this->get($url)->assertOk()->assertHeader('Content-Type', "image/{$format}");
});

it('answers 500 when the fallback format itself fails to generate', function (): void {
    $media = rotatedPhoto();
    failingToEncode('jpg');

    $this->get($media->url('card', 40))->assertInternalServerError();
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('answers 404 and writes nothing for anything not declared', function (int $segment, string $value): void {
    $this->get(tampered(rotatedPhoto()->url('card', 40), $segment, $value))->assertNotFound();

    expect(Storage::disk('public')->allFiles())->toBe([]);
})->with([
    'unknown Media' => [4, '01kb0000000000000000000000'],
    'stale version' => [5, '00000000'],
    'unknown preset' => [6, 'banner-40.jpg'],
    'undeclared width' => [6, 'card-30.jpg'],
    'width with a leading zero' => [6, 'card-040.jpg'],
    'format never offered' => [6, 'card-40.gif'],
    'unknown format' => [6, 'card-40.exe'],
]);

it('answers 404 for a Conversion of an SVG or a document', function (string $contents): void {
    $path = temporaryPath() . '/file';
    mkdir(dirname($path));
    file_put_contents($path, $contents);
    $media = resolve(SaveMedia::class)->handle($path);

    $this->get("/media/{$media->id}/00000000/original-10.png")->assertNotFound();
    expect(fn () => $media->url())->toThrow(LogicException::class);
})->with([
    'SVG' => ['<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>'],
    'PDF' => [(string) file_get_contents(fixture('media/brochure.pdf'))],
]);

it('changes the version with the original and the ratio, not with the widths', function (): void {
    $version = fn (Media $media): string => explode('/', $media->url('card', 40))[5];
    $photo = rotatedPhoto();
    $before = $version($photo);

    config(['kami.media.presets' => [ImagePreset::make('card')->ratio(4, 3)->widths([20, 40, 80])]]);
    expect($version($photo))->toBe($before);

    config(['kami.media.presets' => [ImagePreset::make('card')->ratio(16, 9)->widths([20, 40])]]);
    expect($version($photo))->not->toBe($before);

    config(['kami.media.presets' => [ImagePreset::make('card')->ratio(4, 3)->widths([20, 40])]]);
    expect($version(resolve(SaveMedia::class)->handle(fixture('media/Holiday Photo.JPG'))))->not->toBe($before);
});

it('generates once: a request waiting for another worker\'s lock serves the file that worker wrote', function (): void {
    $url = rotatedPhoto()->url('card', 40);
    $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
    $lock = Cache::lock("kami-media:{$path}", 60);
    $lock->acquire();

    Sleep::fake();
    Sleep::whenFakingSleep(function () use ($lock, $path): void {
        Storage::disk('public')->put($path, 'written by the other worker');
        $lock->release();
    });

    expect(file_get_contents($this->get($url)->assertOk()->baseResponse->getFile()->getPathname()))->toBe('written by the other worker');
});

it('answers 503 when another worker keeps the lock', function (): void {
    $url = rotatedPhoto()->url('card', 40);
    Cache::lock('kami-media:' . ltrim((string) parse_url($url, PHP_URL_PATH), '/'), 600)->acquire();
    Sleep::fake(syncWithCarbon: true);

    $this->get($url)->assertServiceUnavailable()->assertHeader('Retry-After', '5');
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('stays sessionless with the front security headers inside the web group', function (): void {
    Route::middleware('web')->group(fn () => Kami::routes());

    $this->get(rotatedPhoto()->url())->assertOk()->assertHeaderMissing('Set-Cookie')->assertHeader('Content-Security-Policy');
});

it('reads presets back from config:cache', function (): void {
    $preset = ImagePreset::make('card')->ratio(1.91, 1)->widths([320, 640])->sizes('50vw');

    expect(eval('return ' . var_export($preset, true) . ';'))->toEqual($preset);
});

it('fails on an invalid preset, naming it', function (ImagePreset | array | string $preset, string $message): void {
    config(['kami.media.presets' => is_array($preset) ? $preset : [$preset]]);

    expect(fn () => rotatedPhoto()->url())->toThrow(InvalidArgumentException::class, $message);
})->with([
    'not a preset' => ['card', 'Every entry of kami.media.presets is an'],
    'bad handle' => [fn () => ImagePreset::make('Card_1')->widths([320]), 'Image Preset "Card_1": a handle is lowercase'],
    'no width' => [fn () => ImagePreset::make('card'), 'Image Preset "card": declare at least one width.'],
    'non-positive width' => [fn () => ImagePreset::make('card')->widths([320, 0]), 'Image Preset "card": every width is between 1 and 999999 pixels.'],
    'width beyond the route' => [fn () => ImagePreset::make('card')->widths([1_000_000]), 'Image Preset "card": every width is between 1 and 999999 pixels.'],
    'non-positive ratio' => [fn () => ImagePreset::make('card')->ratio(4, 0)->widths([320]), 'Image Preset "card": both sides of the ratio are positive.'],
    'shipped handle' => [fn () => ImagePreset::make('original')->widths([320]), 'Image Preset "original": the handle is one of Kami\'s own presets'],
    'twice' => [fn () => [ImagePreset::make('card')->widths([1]), ImagePreset::make('card')->widths([2])], 'Image Preset "card": the handle appears twice.'],
]);
