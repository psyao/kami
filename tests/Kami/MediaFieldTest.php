<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Psyao\Kami\Admin\Internal\EntryResource\EditEntry;
use Psyao\Kami\Admin\Internal\MediaResource;
use Psyao\Kami\Content\Actions\DuplicateEntry;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Group;
use Psyao\Kami\Content\Fields\Media as MediaField;
use Psyao\Kami\Content\Fields\Text;
use Psyao\Kami\Content\Internal\ContentTypes;
use Psyao\Kami\Media\Actions\SaveMedia;
use Psyao\Kami\Media\Media;
use Workbench\App\Content\Article;
use Workbench\App\Content\Page;
use Workbench\App\Content\Project;
use Workbench\App\Content\Tag;

uses(RefreshDatabase::class);

/** Media Fields only, so no table; what each test declares in `$declared`. */
final class Illustrated extends ContentType
{
    /** @var array<mixed> */
    public static array $declared = [];

    public function fields(): array
    {
        return self::$declared; // @phpstan-ignore return.type
    }
}

beforeEach(function (): void {
    Storage::fake('local');
    Illustrated::$declared = [MediaField::make('cover')->images(), MediaField::make('gallery')->multiple()];
    config(['kami.types' => [Article::class, Illustrated::class]]);
});

function storedMedia(string $fixture = 'media/pixel.png'): Media
{
    return resolve(SaveMedia::class)->handle(fixture($fixture));
}

/** @param array<string, mixed> $values */
function saveIllustrated(array $values, ?Entry $entry = null): Entry
{
    return resolve(SaveEntry::class)->handle(Illustrated::class, ['title' => 'Source', 'status' => 'published', ...$values], $entry, 'fr');
}

/** @param array<string, mixed> $values */
function illustratedErrors(array $values): array
{
    try {
        saveIllustrated($values);
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

function readIllustrated(): Entry
{
    return Entry::query()->ofType('illustrated')->with('cover', 'gallery')->sole();
}

it('reads a single Field as a Media item, a multiple one as a Collection in pick order', function (): void {
    [$photo, $pixel, $brochure] = [storedMedia('media/Holiday Photo.JPG'), storedMedia(), storedMedia('media/brochure.pdf')];
    saveIllustrated(['cover' => $photo->id, 'gallery' => [$brochure->id, $photo->id, $pixel->id]]);

    $read = readIllustrated();

    expect($read->cover)->toBeInstanceOf(Media::class)
        ->and($read->cover->id)->toBe($photo->id)
        ->and($read->gallery)->toBeInstanceOf(Collection::class)
        ->and($read->gallery->pluck('id')->all())->toBe([$brochure->id, $photo->id, $pixel->id]);
});

it('reads an empty single Field as null and an empty multiple one as an empty Collection', function (): void {
    $entry = saveIllustrated(['cover' => storedMedia()->id, 'gallery' => [storedMedia()->id]]);
    saveIllustrated(['cover' => null, 'gallery' => []], $entry);

    $read = readIllustrated();

    expect($read->cover)->toBeNull()
        ->and($read->gallery)->toBeEmpty();
});

it('keeps one pick per Locale on a translatable Field, a blank Locale falling back to the default one', function (): void {
    Illustrated::$declared = [MediaField::make('cover')->translatable()];
    [$photo, $pixel] = [storedMedia('media/Holiday Photo.JPG'), storedMedia()];
    $entry = saveIllustrated(['cover' => $photo->id]);
    resolve(SaveEntry::class)->handle(Illustrated::class, ['title' => 'Source', 'status' => 'published'], $entry, 'en');

    $read = fn (string $locale) => Entry::query()->ofType('illustrated')->inLocale($locale)->with('cover')->sole();

    expect($read('en')->cover?->id)->toBe($photo->id)
        ->and($read('en')->fallbackLocale('cover'))->toBe('fr');

    resolve(SaveEntry::class)->handle(Illustrated::class, ['cover' => $pixel->id], $entry, 'en');

    expect($read('en')->cover?->id)->toBe($pixel->id)
        ->and($read('fr')->cover?->id)->toBe($photo->id);
});

it('refuses an unknown id, another Site, a document under images(), a duplicate and a second id on a single Field, on the Field', function (Closure $given): void {
    [$handle, $value] = $given();

    expect(array_keys(illustratedErrors([$handle => $value])))->toBe([$handle]);
})->with([
    'unknown' => fn () => ['cover', '01JAAAAAAAAAAAAAAAAAAAAAAA'],
    'other Site' => function () {
        $media = storedMedia();
        DB::table('kami_media')->where('id', $media->id)->update(['site' => 'other']);

        return ['gallery', [$media->id]];
    },
    'document' => fn () => ['cover', storedMedia('media/brochure.pdf')->id],
    'duplicate' => function () {
        $id = storedMedia()->id;

        return ['gallery', [$id, $id]];
    },
    'two on a single Field' => fn () => ['cover', [storedMedia()->id, storedMedia('media/Holiday Photo.JPG')->id]],
    'not an id' => fn () => ['cover', 42],
]);

it('checks existence, Site and family in one query per Field', function (): void {
    $ids = [storedMedia()->id, storedMedia('media/brochure.pdf')->id];

    DB::enableQueryLog();
    saveIllustrated(['gallery' => $ids]);

    expect(collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'kami_media'))->count())->toBe(1);
});

it('loads with the Entries in two queries, the Media items and their Translations, whatever their number', function (): void {
    [$photo, $pixel] = [storedMedia('media/Holiday Photo.JPG'), storedMedia()];
    saveIllustrated(['cover' => $photo->id, 'gallery' => [$pixel->id]]);
    saveIllustrated(['title' => 'Second', 'cover' => $pixel->id, 'gallery' => [$photo->id, $pixel->id]]);

    DB::enableQueryLog();
    $entries = Entry::query()->ofType('illustrated')->with('cover', 'gallery')->get();
    $entries->each(fn (Entry $entry) => [$entry->cover?->alt(), $entry->gallery->each(fn (Media $media) => $media->alt())]);

    // Sources, their Translations; rows; Media items, their Translations.
    expect(DB::getQueryLog())->toHaveCount(5);
});

it('throws on an unloaded read among several Entries under strict mode, and loads a single one', function (): void {
    $media = storedMedia();
    $illustrated = saveIllustrated(['cover' => $media->id]);
    saveIllustrated(['title' => 'Second']);

    // By key: an unordered first() may return the Entry without a cover on PostgreSQL (#433).
    expect(fn () => Entry::query()->ofType('illustrated')->get()->first()->cover)->toThrow(LazyLoadingViolationException::class)
        ->and(Entry::query()->ofType('illustrated')->find($illustrated->getKey())->cover?->id)->toBe($media->id);
});

it('keeps its links in a duplicate', function (): void {
    [$photo, $pixel] = [storedMedia('media/Holiday Photo.JPG'), storedMedia()];
    $entry = saveIllustrated(['cover' => $photo->id, 'gallery' => [$pixel->id, $photo->id]]);

    $copy = resolve(DuplicateEntry::class)->handle($entry);
    $read = Entry::query()->withUnpublished()->with('cover', 'gallery')->findOrFail($copy->id);

    expect($read->cover?->id)->toBe($photo->id)
        ->and($read->gallery->pluck('id')->all())->toBe([$pixel->id, $photo->id]);
});

it('refuses what a Media Field cannot honour, at first read', function (Closure $fields, string $message): void {
    Illustrated::$declared = $fields();

    expect(fn () => resolve(ContentTypes::class)->all())->toThrow(InvalidArgumentException::class, $message);
})->with([
    'filterable' => [fn () => [MediaField::make('cover')->filterable()], 'the Media Field "cover" cannot be filterable'],
    'default' => [fn () => [MediaField::make('cover')->default('x')], 'cannot take a default'],
    'in a Group' => [fn () => [Group::make('rows', [MediaField::make('cover')])], 'cannot hold the Media Field "cover"'],
    'as a target' => [fn () => [MediaField::make('cover'), Text::make('note')->visibleWhenFilled('cover')], 'according to the Media Field "cover"'],
]);

it('keeps a Media item an Entry links, through an indexed foreign key', function (): void {
    $media = storedMedia();
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Source', 'author' => 'Ada'], locale: 'fr');
    DB::table('kami_references')->insert(['id' => (string) str()->ulid(), 'source_entry_id' => $entry->id, 'field' => 'cover', 'target_media_id' => $media->id, 'position' => 0]);

    // In a savepoint: a refused statement aborts PostgreSQL's whole transaction otherwise.
    expect(fn () => DB::transaction(fn () => $media->delete()))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('kami_references')->insert(['id' => (string) str()->ulid(), 'source_entry_id' => $entry->id, 'field' => 'cover', 'target_media_id' => (string) str()->ulid(), 'position' => 1])))->toThrow(QueryException::class)
        ->and(collect(Schema::getIndexes('kami_references'))->pluck('columns')->contains(['target_media_id']))->toBeTrue();

    // The source's deletion still takes its References along.
    DB::table('kami_paths')->delete();
    Entry::query()->withUnpublished()->findOrFail($entry->id)->forceDelete();
    expect(DB::table('kami_references')->count())->toBe(0);
});

describe('picker', function (): void {
    beforeEach(function (): void {
        Storage::fake('public');
        config(['kami.types' => [Article::class, Page::class, Tag::class, Project::class]]);
        $this->project = resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Kami'], locale: 'fr');
    });

    /** The Project's edit page, for a User holding these Permissions, or the administrator. */
    function illustratedProject(string | array $role = 'administrator'): Testable
    {
        return entriesPage(EditEntry::class, $role, ['record' => test()->project->id], 'project');
    }

    function pickerUpload(string $field): TestAction
    {
        return TestAction::make('createOption')->schemaComponent($field, 'form');
    }

    it('saves a single pick and a multiple one in the order given, and fills them back so', function (): void {
        [$photo, $pixel] = [storedMedia('media/Holiday Photo.JPG'), storedMedia()];

        illustratedProject()->set('data.cover', $photo->id)->set('data.gallery', [$pixel->id, $photo->id])->call('save')->assertHasNoErrors();

        expect(illustratedProject()->get('data.cover'))->toBe($photo->id)
            ->and(illustratedProject()->get('data.gallery'))->toBe([$pixel->id, $photo->id]);

        illustratedProject()->set('data.gallery', [$photo->id, $pixel->id])->call('save')->assertHasNoErrors();

        expect(illustratedProject()->get('data.gallery'))->toBe([$photo->id, $pixel->id]);
    });

    it('shows a document picked for images as an error on the picker', function (): void {
        illustratedProject()->set('data.cover', storedMedia('media/brochure.pdf')->id)->call('save')->assertHasErrors(['data.cover']);
    });

    it('searches the Site\'s Media by name and alt text, images only under images(), and labels them with their thumbnail and name', function (): void {
        $photo = resolve(SaveMedia::class)->handle(fixture('media/Holiday Photo.JPG'), ['alt' => 'Une plage au soleil']);
        $brochure = storedMedia('media/brochure.pdf');
        $elsewhere = storedMedia();
        DB::table('kami_media')->where('id', $elsewhere->id)->update(['site' => 'other']);

        $form = illustratedProject()->instance()->getSchema('form');
        $cover = $form->getComponent('cover');
        $gallery = $form->getComponent('gallery');

        expect(array_keys($cover->getSearchResults('plage')))->toBe([$photo->id])
            ->and(array_keys($cover->getSearchResults('holiday')))->toBe([$photo->id])
            ->and($cover->getSearchResults('brochure'))->toBe([])
            ->and($cover->getSearchResults('pixel'))->toBe([])
            ->and(array_keys($gallery->getSearchResults('brochure')))->toBe([$brochure->id])
            ->and($cover->getSearchResults('plage')[$photo->id])->toContain(MediaResource::thumbnail($photo)->url(), 'holiday-photo')
            ->and($gallery->getSearchResults('brochure')[$brochure->id])->toContain('brochure')->not->toContain('<img');
    });

    it('labels an SVG with its own file', function (): void {
        $logo = resolve(SaveMedia::class)->handle(fixture('media/logo.svg'));

        expect(illustratedProject()->instance()->getSchema('form')->getComponent('cover')->getSearchResults('logo')[$logo->id])
            ->toContain('<img src="' . e($logo->url()) . '"');
    });

    it('escapes a name in its label', function (): void {
        $media = storedMedia();
        DB::table('kami_media')->where('id', $media->id)->update(['name' => '<b>bold</b>']);

        expect(illustratedProject()->instance()->getSchema('form')->getComponent('gallery')->getSearchResults('bold')[$media->id])
            ->toContain('&lt;b&gt;bold&lt;/b&gt;')->not->toContain('<b>');
    });

    it('uploads a file from the picker through SaveMedia and picks it, its thumbnail after the response', function (): void {
        $page = illustratedProject()
            ->callAction(pickerUpload('cover'), ['file' => UploadedFile::fake()->createWithContent('Holiday Photo.JPG', (string) file_get_contents(fixture('media/Holiday Photo.JPG')))])
            ->assertHasNoErrors();

        $media = Media::query()->sole();

        expect($page->get('data.cover'))->toBe($media->id)
            ->and($media->name)->toBe('holiday-photo');

        app(DeferredCallbackCollection::class)->invoke();
        expect(Storage::disk('public')->exists(MediaResource::thumbnail($media)->path()))->toBeTrue();
    });

    it('adds a file uploaded from a multiple picker after the picks', function (): void {
        $pixel = storedMedia();

        $page = illustratedProject()->set('data.gallery', [$pixel->id])
            ->callAction(pickerUpload('gallery'), ['file' => UploadedFile::fake()->createWithContent('brochure.pdf', (string) file_get_contents(fixture('media/brochure.pdf')))])
            ->assertHasNoErrors();

        expect($page->get('data.gallery'))->toBe([$pixel->id, Media::query()->where('name', 'brochure')->sole()->id]);
    });

    it('shows a refused upload as an error on the file', function (): void {
        illustratedProject()
            ->callAction(pickerUpload('gallery'), ['file' => UploadedFile::fake()->createWithContent('script.jpg', (string) file_get_contents(fixture('media/script.jpg')))])
            ->assertHasErrors();

        expect(Media::query()->exists())->toBeFalse();
    });

    it('takes kami.media.create to upload from the picker', function (): void {
        illustratedProject(['kami.entries.project.view', 'kami.entries.project.update'])->assertActionHidden(pickerUpload('cover'));
        illustratedProject(['kami.entries.project.view', 'kami.entries.project.update', 'kami.media.create'])->assertActionVisible(pickerUpload('cover'));
    });
});
