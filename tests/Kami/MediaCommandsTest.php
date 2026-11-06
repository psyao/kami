<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Image\ImageException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Actions\TrashEntry;
use Psyao\Kami\Media\Actions\SaveMedia;
use Psyao\Kami\Media\ImagePreset;
use Psyao\Kami\Media\Internal\ImageFormats;
use Psyao\Kami\Media\Media;
use Workbench\App\Content\Article;
use Workbench\App\Content\Page;
use Workbench\App\Content\Project;
use Workbench\App\Content\Tag;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
    config([
        'kami.types' => [Article::class, Page::class, Tag::class, Project::class],
        'kami.media.presets' => [ImagePreset::make('card')->ratio(4, 3)->widths([20, 40])],
    ]);
});

/** A fixture saved as a Media item. */
function mediaFixture(string $fixture): Media
{
    return resolve(SaveMedia::class)->handle(fixture("media/{$fixture}"));
}

/** The file names under a Media item's directory on the public disk, sorted. */
function servedFiles(Media $media): array
{
    return collect(Storage::disk('public')->allFiles("media/{$media->id}"))->map(fn (string $path): string => basename($path))->sort()->values()->all();
}

/** Where a URL's file sits on the public disk. */
function diskPath(string $url): string
{
    return ltrim((string) parse_url($url, PHP_URL_PATH), '/');
}

describe('warm', function (): void {
    beforeEach(fn () => failingToEncode('avif', 'webp'));

    it('writes every declared Conversion of every image, and each document\'s and SVG\'s own file', function (): void {
        $photo = mediaFixture('rotated-gps.jpg');
        $pixel = mediaFixture('pixel.png');
        $brochure = mediaFixture('brochure.pdf');
        $logo = mediaFixture('logo.svg');

        $this->artisan('kami:media:warm')
            ->expectsOutputToContain('4 Media items: 12 files written, 0 already there.')
            ->assertSuccessful();

        // Stored 60 × 40 with EXIF Orientation 6, so 40 pixels wide once oriented.
        expect(servedFiles($photo))->toBe(['admin-thumb-320.jpg', 'card-20.jpg', 'card-40.jpg', 'kami-social-1200.jpg', 'original-40.jpg'])
            ->and(servedFiles($pixel))->toBe(['admin-thumb-320.png', 'card-20.png', 'card-40.png', 'kami-social-1200.png', 'original-20.png'])
            ->and(servedFiles($brochure))->toBe(['brochure.pdf'])
            ->and(servedFiles($logo))->toBe(['logo.svg']);
        Storage::disk('public')->assertExists([diskPath($photo->url('card', 40)), diskPath($brochure->url())]);
    });

    it('skips the files already there, so running it again resumes', function (): void {
        $photo = mediaFixture('rotated-gps.jpg');
        $card = diskPath($photo->url('card', 40));
        Storage::disk('public')->put($card, 'on disk');

        $this->artisan('kami:media:warm')->expectsOutputToContain('1 Media item: 4 files written, 1 already there.')->assertSuccessful();
        $this->artisan('kami:media:warm')->expectsOutputToContain('1 Media item: 0 files written, 5 already there.')->assertSuccessful();

        expect(Storage::disk('public')->get($card))->toBe('on disk');
    });

    it('writes the chosen presets only, `file` meaning a document\'s or an SVG\'s own file', function (): void {
        $photo = mediaFixture('rotated-gps.jpg');
        $brochure = mediaFixture('brochure.pdf');

        $this->artisan('kami:media:warm', ['--preset' => ['card', 'card']])->expectsOutputToContain('2 files written, 0 already there.')->assertSuccessful();

        expect(servedFiles($photo))->toBe(['card-20.jpg', 'card-40.jpg'])
            ->and(servedFiles($brochure))->toBe([]);

        $this->artisan('kami:media:warm', ['--preset' => ['file', 'original']])->assertSuccessful();

        expect(servedFiles($photo))->toBe(['card-20.jpg', 'card-40.jpg', 'original-40.jpg'])
            ->and(servedFiles($brochure))->toBe(['brochure.pdf']);
    });

    it('refuses a preset that is not declared, writing nothing', function (): void {
        mediaFixture('rotated-gps.jpg');

        $this->artisan('kami:media:warm', ['--preset' => ['card', 'hero']])
            ->expectsOutputToContain('No Image Preset [hero] is declared.')
            ->assertFailed();

        expect(Storage::disk('public')->allFiles())->toBe([]);
    });

    it('writes the Media items an Entry uses only, in any Locale, the Entry in the Trash too', function (): void {
        $cover = mediaFixture('rotated-gps.jpg');
        $gallery = mediaFixture('pixel.png');
        $other = mediaFixture('brochure.pdf');
        $entry = resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Kami', 'cover' => $cover->id], locale: 'fr');
        resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Kami', 'gallery' => [$gallery->id]], entry: $entry, locale: 'en');
        resolve(TrashEntry::class)->handle($entry);

        $this->artisan('kami:media:warm', ['--entry' => $entry->id, '--preset' => ['original', 'file']])
            ->expectsOutputToContain('2 Media items: 2 files written, 0 already there.')
            ->assertSuccessful();

        expect(servedFiles($cover))->toBe(['original-40.jpg'])
            ->and(servedFiles($gallery))->toBe(['original-20.png'])
            ->and(servedFiles($other))->toBe([]);
    });

    it('refuses an Entry that does not exist, and says when an Entry uses no Media', function (): void {
        $this->artisan('kami:media:warm', ['--entry' => '01JAAAAAAAAAAAAAAAAAAAAAAA'])
            ->expectsOutputToContain('No Entry [01JAAAAAAAAAAAAAAAAAAAAAAA] exists.')
            ->assertFailed();

        $entry = resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Kami'], locale: 'fr');

        $this->artisan('kami:media:warm', ['--entry' => $entry->id])->expectsOutputToContain('Nothing to warm.')->assertSuccessful();
    });

    it('reports a Conversion that fails and goes on with the others, then fails', function (): void {
        Exceptions::fake();
        $photo = mediaFixture('rotated-gps.jpg');
        $pixel = mediaFixture('pixel.png');
        failingToEncode('avif', 'webp', 'jpg');

        $this->artisan('kami:media:warm', ['--preset' => ['card']])
            ->expectsOutputToContain("Media [{$photo->id}] card 20 jpg: No encoder for jpg.")
            ->expectsOutputToContain('2 Media items: 2 files written, 0 already there, 2 failed.')
            ->assertFailed();

        expect(servedFiles($photo))->toBe([])
            ->and(servedFiles($pixel))->toBe(['card-20.png', 'card-40.png']);
        Exceptions::assertReported(ImageException::class);
    });
});

it('warms every format this host encodes', function (): void {
    config(['kami.media.avif' => false]);

    if (! isset(resolve(ImageFormats::class)->available()['webp'])) {
        // CI's images encode WebP: there, a missing encoder fails the WebP tests of `ConversionsTest` rather than skips them.
        $this->markTestSkipped('No Image driver encodes WebP on this host.');
    }

    $photo = mediaFixture('rotated-gps.jpg');

    $this->artisan('kami:media:warm', ['--preset' => ['card']])->assertSuccessful();

    expect(servedFiles($photo))->toBe(['card-20.jpg', 'card-20.webp', 'card-40.jpg', 'card-40.webp']);
});

describe('prune', function (): void {
    it('deletes stale versions, removed presets, widths and formats, files of deleted Media and leftovers, and keeps valid ones', function (): void {
        config(['kami.media.avif' => false]);
        $photo = mediaFixture('rotated-gps.jpg');
        $brochure = mediaFixture('brochure.pdf');
        $pixel = mediaFixture('pixel.png');
        $disk = Storage::disk('public');
        $card = diskPath($photo->url('card', 40));
        $version = dirname($card);
        $kept = [
            $card,
            "{$version}/card-20.jpg",
            // WebP is kept whether this host encodes it or not: the web may, where the command line does not.
            "{$version}/card-40.webp",
            diskPath($photo->url('kami-social')),
            diskPath($photo->url()),
            diskPath($brochure->url()),
            'media/.htaccess',
            'media/kami-doctor.svg',
            // Being written now, by a request or a `warm` run.
            '.kami-tmp/recent',
        ];
        $stale = [
            "media/{$photo->id}/0123abcd/card-40.jpg",
            "{$version}/card-60.jpg",
            "{$version}/hero-40.jpg",
            "{$version}/card-40.avif",
            "{$version}/card-40.gif",
            // `kami-social` is offered in the fallback format alone.
            preg_replace('/\.jpg$/', '.webp', diskPath($photo->url('kami-social'))),
            "media/{$brochure->id}/0123abcd/brochure.pdf",
            dirname(diskPath($brochure->url())) . '/former-name.pdf',
            // An item whose files are all stale loses its directory.
            "media/{$pixel->id}/0123abcd/card-20.png",
            'media/01JAAAAAAAAAAAAAAAAAAAAAAA/0123abcd/card-40.jpg',
            '.kami-tmp/killed',
        ];

        foreach ([...$kept, ...$stale] as $path) {
            $disk->put($path, 'file');
        }

        touch($disk->path('.kami-tmp/killed'), now()->subHours(2)->getTimestamp());

        $this->artisan('kami:media:prune')
            ->expectsOutputToContain('Deleted 11 files.')
            ->assertSuccessful();

        expect(collect($disk->allFiles())->sort()->values()->all())->toBe(collect($kept)->sort()->values()->all())
            ->and($disk->directories("media/{$photo->id}"))->not->toContain("media/{$photo->id}/0123abcd")
            ->and($disk->directories('media'))->not->toContain('media/01JAAAAAAAAAAAAAAAAAAAAAAA')
            ->not->toContain("media/{$pixel->id}");
    });

    it('keeps AVIF while kami.media.avif allows it', function (): void {
        $photo = mediaFixture('rotated-gps.jpg');
        $avif = preg_replace('/\.jpg$/', '.avif', diskPath($photo->url('card', 40)));
        Storage::disk('public')->put($avif, 'file');

        $this->artisan('kami:media:prune')->expectsOutputToContain('Deleted 0 files.')->assertSuccessful();

        Storage::disk('public')->assertExists($avif);
    });

    it('deletes what a deleted Media item left, as a crash after its commit does', function (): void {
        $photo = mediaFixture('rotated-gps.jpg');
        $path = diskPath($photo->url('card', 40));
        Storage::disk('public')->put($path, 'file');
        // The row goes; a worker killed before the files' deletion leaves them.
        Media::query()->whereKey($photo->id)->delete();

        $this->artisan('kami:media:prune')->expectsOutputToContain('Deleted 1 file.')->assertSuccessful();

        expect(Storage::disk('public')->directories('media'))->toBe([]);
    });
});

it('runs both commands within a small, steady memory over a library of fixture images', function (): void {
    failingToEncode('avif', 'webp');

    $directory = temporaryPath();
    mkdir($directory);

    foreach (range(1, 14) as $copy) {
        foreach (['rotated-gps.jpg', 'pixel.png'] as $fixture) {
            // The same picture with other bytes: a Media item of its own.
            $path = "{$directory}/{$copy}-{$fixture}";
            file_put_contents($path, file_get_contents(fixture("media/{$fixture}")) . str_repeat("\0", $copy));
            resolve(SaveMedia::class)->handle($path);
        }
    }

    mediaFixture('brochure.pdf');
    mediaFixture('logo.svg');

    foreach (['kami:media:warm', 'kami:media:prune'] as $command) {
        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();

        $this->artisan($command)->assertSuccessful();

        expect(memory_get_peak_usage() - $before)->toBeLessThan(32 * 1024 * 1024);
    }

    expect(Storage::disk('public')->allFiles('media'))->toHaveCount(142);
});
