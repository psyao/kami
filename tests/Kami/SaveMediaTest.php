<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Psyao\Kami\Media\Actions\SaveMedia;
use Psyao\Kami\Media\Events\MediaSaved;
use Psyao\Kami\Media\Media;
use Psyao\Kami\Sites\Internal\Site;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

/** @param array<string, mixed> $values */
function saveMedia(UploadedFile | string | null $file, array $values = [], ?Media $media = null, ?string $locale = null, ?User $actor = null): Media
{
    return resolve(SaveMedia::class)->handle($file, $values, $media, $locale, $actor);
}

/** A file of the test's own, holding these bytes. */
function mediaFile(string $contents, string $name = 'upload.bin'): string
{
    $path = temporaryPath() . "/{$name}";
    mkdir(dirname($path));
    file_put_contents($path, $contents);

    return $path;
}

/** The reason a save is refused on `file`, or null when it is saved. */
function refusal(UploadedFile | string $file): ?string
{
    try {
        saveMedia($file);
    } catch (ValidationException $exception) {
        return $exception->errors()['file'][0] ?? null;
    }

    return null;
}

it('stores an image from a local path and records what it read from it', function (): void {
    $media = saveMedia(fixture('media/Holiday Photo.JPG'));

    expect(Media::query()->findOrFail($media->id))
        ->site->toBe('default')
        ->name->toBe('holiday-photo')
        ->mime->toBe('image/jpeg')
        ->family->toBe('image')
        ->size->toBe(738)
        ->width->toBe(64)
        ->height->toBe(48)
        ->checksum->toBe('757a5d82e7b29e849829a5385658d039e8947d8cd3e0f930b7efc2e33ac0c814')
        ->credit->toBeNull()
        ->isImage()->toBeTrue()
        ->isDecorative()->toBeFalse()
        ->and(Storage::disk('local')->files('media'))->toBe(["media/{$media->id}.jpg"])
        ->and(Storage::disk('local')->get("media/{$media->id}.jpg"))->toBe(file_get_contents(fixture('media/Holiday Photo.JPG')));
});

it('names an upload after its client filename and stores the extension its content implies', function (): void {
    $media = saveMedia(new UploadedFile(fixture('media/pixel.png'), 'Team Photo.jpg', test: true));

    expect($media)
        ->name->toBe('team-photo')
        ->mime->toBe('image/png')
        ->width->toBe(20)
        ->height->toBe(10)
        ->and(Storage::disk('local')->files('media'))->toBe(["media/{$media->id}.png"]);
});

it('stores a PDF as a document, with no dimensions', function (): void {
    $media = saveMedia(fixture('media/brochure.pdf'));

    expect($media)
        ->family->toBe('document')
        ->mime->toBe('application/pdf')
        ->width->toBeNull()
        ->height->toBeNull()
        ->isImage()->toBeFalse()
        ->and(Storage::disk('local')->files('media'))->toBe(["media/{$media->id}.pdf"]);
});

it('reads an SVG\'s dimensions from its size in pixels or its viewBox, and none when it states neither', function (string $svg, ?int $width, ?int $height): void {
    $media = saveMedia(mediaFile($svg, 'logo.svg'));

    expect($media)->mime->toBe('image/svg+xml')->width->toBe($width)->height->toBe($height);
})->with([
    'viewBox' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 80"/>', 120, 80],
    'pixels over viewBox' => ['<svg xmlns="http://www.w3.org/2000/svg" width="300px" height="200" viewBox="0 0 120 80"/>', 300, 200],
    'relative size, viewBox' => ['<svg xmlns="http://www.w3.org/2000/svg" width="100%" height="100%" viewBox="0,0,48.4,24"/>', 48, 24],
    'neither' => ['<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>', null, null],
    'opening comment' => ['<!-- Generator: Adobe Illustrator --><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 80"/>', 120, 80],
    'leading whitespace' => ["\n  <svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 120 80\"/>", 120, 80],
]);

it('refuses a file whose content is not in the allowlist, whatever its name says', function (): void {
    expect(refusal(fixture('media/script.jpg')))->toBe('Files of type text/x-php are not accepted.')
        ->and(Media::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('refuses an image that does not decode', function (string $contents): void {
    expect(refusal(mediaFile($contents)))->toBe('This image cannot be read: the file is damaged or not an image.');
})->with([
    'truncated' => fn (): string => substr((string) file_get_contents(fixture('media/Holiday Photo.JPG')), 0, 300),
    'not an SVG document' => '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><unclosed></svg>',
]);

it('refuses an image over the pixel limit, which config raises', function (): void {
    config(['kami.media.max_pixels' => 3_000]);

    expect(refusal(fixture('media/Holiday Photo.JPG')))->toBe('This image is larger than 0.003 megapixels: reduce it before uploading.');

    config(['kami.media.max_pixels' => 3_072]);

    expect(refusal(fixture('media/Holiday Photo.JPG')))->toBeNull();
});

it('refuses video and audio even when config lists them, under any family', function (array $mimes): void {
    config(['kami.media.mimes' => $mimes]);

    expect(refusal(mediaFile("\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2", 'clip.mp4')))->toBe('Files of type video/mp4 are not supported yet.');
})->with([
    'as video' => [['video' => ['video/mp4']]],
    'as a document' => [['document' => ['video/mp4']]],
]);

it('refuses a text file that is not an SVG', function (): void {
    expect(refusal(mediaFile('Opening hours', 'hours.txt')))->toBe('Files of type text/plain are not accepted.');
});

it('accepts a type config adds to a family', function (): void {
    config(['kami.media.mimes.document' => ['application/pdf', 'text/plain']]);

    expect(saveMedia(mediaFile('Opening hours', 'hours.txt')))->family->toBe('document')->mime->toBe('text/plain');
});

it('stores the original on the disk config names, any Laravel disk', function (): void {
    Storage::fake('originals');
    config(['kami.media.originals_disk' => 'originals']);

    $media = saveMedia(fixture('media/brochure.pdf'));

    expect(Storage::disk('originals')->get("media/{$media->id}.pdf"))->toBe(file_get_contents(fixture('media/brochure.pdf')))
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('reads alt text and caption in the current Locale, falling back to the default Locale\'s when blank', function (): void {
    config(['kami.sites.default.locales' => ['en', 'fr']]);
    $media = saveMedia(fixture('media/Holiday Photo.JPG'), ['alt' => 'A red square', 'caption' => 'Our holidays']);
    saveMedia(null, ['alt' => 'Un carré rouge', 'caption' => ''], $media, 'fr');

    Site::useLocale('fr');
    $media = Media::query()->findOrFail($media->id);

    expect($media->alt())->toBe('Un carré rouge')
        ->and($media->caption())->toBe('Our holidays');

    Site::useLocale('en');

    expect($media->alt())->toBe('A red square');
});

it('reads no alt text when neither Locale has one', function (): void {
    expect(saveMedia(fixture('media/Holiday Photo.JPG')))->alt()->toBeNull()->caption()->toBeNull();
});

it('writes credit and decorative for every Locale, keeping what a save leaves out', function (): void {
    config(['kami.sites.default.locales' => ['en', 'fr']]);
    $media = saveMedia(fixture('media/Holiday Photo.JPG'), ['alt' => 'A red square']);

    $media = saveMedia(null, ['credit' => 'Ada Lovelace', 'decorative' => true], $media, 'fr');

    expect($media)
        ->credit->toBe('Ada Lovelace')
        ->isDecorative()->toBeTrue()
        ->alt()->toBe('A red square')
        ->and(Media::query()->count())->toBe(1);
});

it('clears decorative with an empty value', function (): void {
    $media = saveMedia(fixture('media/Holiday Photo.JPG'), ['decorative' => true]);

    expect(saveMedia(null, ['decorative' => ''], $media))->isDecorative()->toBeFalse();
});

it('refuses a call it cannot honour', function (Closure $save, string $exception): void {
    expect($save)->toThrow($exception);
})->with([
    'a file for an existing Media item' => [fn (): Media => saveMedia(fixture('media/pixel.png'), media: saveMedia(fixture('media/brochure.pdf'))), LogicException::class],
    'an undeclared Locale' => [fn (): Media => saveMedia(fixture('media/brochure.pdf'), locale: 'de'), InvalidArgumentException::class],
    'a new Media item without a file' => [fn (): Media => saveMedia(null, ['alt' => 'Nothing']), InvalidArgumentException::class],
]);

it('refuses a value it does not know', function (): void {
    expect(fn (): Media => saveMedia(fixture('media/brochure.pdf'), ['title' => 'Brochure']))
        ->toThrow(ValidationException::class, 'The title field is prohibited.');
});

it('dispatches MediaSaved after commit with the Media item, the Locale, whether it was created and the actor', function (): void {
    config(['kami.sites.default.locales' => ['en', 'fr']]);
    $seen = new ArrayObject;
    Event::listen(MediaSaved::class, fn (MediaSaved $event) => $seen->append($event));
    $ada = User::forceCreate(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'secret']);

    $media = saveMedia(fixture('media/Holiday Photo.JPG'), ['alt' => 'A red square'], actor: $ada);
    saveMedia(null, ['alt' => 'Un carré rouge'], $media, 'fr');
    rescue(fn (): Media => saveMedia(fixture('media/script.jpg')), report: false);
    rescue(fn () => DB::transaction(function (): never {
        saveMedia(fixture('media/brochure.pdf'));

        throw new RuntimeException('Rolled back');
    }), report: false);

    expect($seen)->toHaveCount(2)
        ->and([$seen[0]->media->id, $seen[0]->locale, $seen[0]->created, $seen[0]->actor?->getAuthIdentifier()])->toBe([$media->id, 'en', true, $ada->id])
        ->and([$seen[1]->media->id, $seen[1]->locale, $seen[1]->created, $seen[1]->actor])->toBe([$media->id, 'fr', false, null])
        ->and($seen[1]->media->translations)->toHaveCount(2);
});
