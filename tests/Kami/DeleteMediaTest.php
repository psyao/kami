<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Actions\TrashEntry;
use Psyao\Kami\Media\Actions\DeleteMedia;
use Psyao\Kami\Media\Actions\SaveMedia;
use Psyao\Kami\Media\Events\MediaDeleted;
use Psyao\Kami\Media\Media;
use Workbench\App\Content\Article;
use Workbench\App\Content\Page;
use Workbench\App\Content\Project;
use Workbench\App\Content\Tag;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
    config(['kami.types' => [Article::class, Page::class, Tag::class, Project::class]]);
});

it('refuses to delete a Media item an Entry uses, naming them, those in the Trash marked, and changes nothing', function (): void {
    $media = resolve(SaveMedia::class)->handle(fixture('media/pixel.png'), ['alt' => 'A pixel']);
    resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Kami', 'cover' => $media->id], locale: 'fr');
    $velox = resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Velox', 'gallery' => [$media->id]], locale: 'fr');
    resolve(TrashEntry::class)->handle($velox);
    Event::fake([MediaDeleted::class]);

    expect(fn () => resolve(DeleteMedia::class)->handle($media))
        ->toThrow(ValidationException::class, 'Kami, Velox (in the Trash)');

    expect(Media::query()->find($media->id)?->alt())->toBe('A pixel')
        ->and(Storage::disk('local')->exists($media->file))->toBeTrue();
    Event::assertNotDispatched(MediaDeleted::class);
});

it('names a single Entry in the singular', function (): void {
    $media = resolve(SaveMedia::class)->handle(fixture('media/pixel.png'));
    resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Kami', 'cover' => $media->id], locale: 'fr');

    expect(fn () => resolve(DeleteMedia::class)->handle($media))
        ->toThrow(ValidationException::class, 'An Entry uses this item: Kami. Remove it from that Entry, or delete the Entry for good if it is in the Trash, first.');
});

it('counts, with no title, the Entries of types the actor may not view', function (): void {
    $media = resolve(SaveMedia::class)->handle(fixture('media/pixel.png'));
    resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Kami', 'cover' => $media->id], locale: 'fr');
    resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Velox', 'gallery' => [$media->id]], locale: 'fr');
    $librarian = signInWith(['kami.media.view', 'kami.media.delete']);

    expect(fn () => resolve(DeleteMedia::class)->handle($media, $librarian))
        ->toThrow(ValidationException::class, 'Entries use this item: 2 restricted Entries. Remove it from them');
});

it('deletes an unused Media item for good: its row, Translations, original and every file served', function (): void {
    Storage::fake('originals');
    config(['kami.media.originals_disk' => 'originals']);
    $media = resolve(SaveMedia::class)->handle(fixture('media/pixel.png'), ['alt' => 'A pixel']);
    // A Conversion, as `RenderConversion` would have written it.
    Storage::disk('public')->put("media/{$media->id}/0123abcd/admin-thumb-320.png", 'thumbnail');
    Event::fake([MediaDeleted::class]);

    resolve(DeleteMedia::class)->handle($media);

    expect(Media::query()->find($media->id))->toBeNull()
        ->and(DB::table('kami_media_translations')->count())->toBe(0)
        ->and(Storage::disk('originals')->allFiles())->toBe([])
        ->and(Storage::disk('public')->allFiles())->toBe([]);
    Event::assertDispatched(MediaDeleted::class, fn (MediaDeleted $event): bool => $event->mediaId === $media->id && $event->site === $media->site);
});

it('keeps the files when the transaction around the deletion rolls back', function (): void {
    $media = resolve(SaveMedia::class)->handle(fixture('media/pixel.png'));

    try {
        DB::transaction(function () use ($media): void {
            resolve(DeleteMedia::class)->handle($media);

            throw new RuntimeException('Rolled back');
        });
    } catch (RuntimeException) {
    }

    expect(Media::query()->find($media->id))->not->toBeNull()
        ->and(Storage::disk('local')->exists($media->file))->toBeTrue();
});
