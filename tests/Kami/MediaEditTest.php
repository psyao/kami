<?php

declare(strict_types=1);

use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Psyao\Kami\Admin\Internal\MediaResource;
use Psyao\Kami\Admin\Internal\MediaResource\EditMedia;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Actions\TrashEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Media\Actions\SaveMedia;
use Psyao\Kami\Media\Media;
use Workbench\App\Content\Project;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    config(['kami.sites.default.locales' => ['en', 'fr']]);
});

/** A Media item's edit page in a Locale, for a User holding the Role, or these Permissions under a Role of their own. */
function mediaPage(Media $media, ?string $locale = null, string | array $role = 'administrator'): Testable
{
    signInWith($role);
    Filament::setCurrentPanel('admin');

    return Livewire::test(EditMedia::class, ['record' => $media->id, ...$locale === null ? [] : ['locale' => $locale]]);
}

it('saves alt text and caption in the Locale edited, and credit and decorative for every Locale', function () {
    $media = resolve(SaveMedia::class)->handle(fixture('media/Holiday Photo.JPG'), ['alt' => 'A beach', 'caption' => 'Our holidays']);

    mediaPage($media, 'fr', ['kami.media.view', 'kami.media.update'])
        // A value of the default Locale is not this one's: saved, it would become its own.
        ->assertSchemaStateSet(['alt' => null, 'caption' => null, 'credit' => null, 'decorative' => false])
        ->fillForm(['alt' => 'Une plage', 'credit' => 'Ada'])
        ->call('save')
        ->assertHasNoFormErrors();

    $media = Media::query()->findOrFail($media->id);
    $alt = fn (string $locale): ?string => $media->translations->firstWhere('locale', $locale)?->alt;

    expect([$alt('en'), $alt('fr'), $media->credit, $media->caption()])->toBe(['A beach', 'Une plage', 'Ada', 'Our holidays']);

    mediaPage($media)->assertSchemaStateSet(['alt' => 'A beach', 'caption' => 'Our holidays', 'credit' => 'Ada']);
});

it('requires alt text in the default Locale unless the image is decorative', function () {
    $media = resolve(SaveMedia::class)->handle(fixture('media/pixel.png'));

    mediaPage($media)->fillForm(['alt' => ''])->call('save')->assertHasFormErrors(['alt' => 'required']);
    mediaPage($media)->fillForm(['alt' => '', 'decorative' => true])->call('save')->assertHasNoFormErrors();
    mediaPage($media, 'fr')->fillForm(['alt' => '', 'decorative' => false, 'caption' => 'Un pixel'])->call('save')->assertHasNoFormErrors();

    expect(Media::query()->findOrFail($media->id)->isDecorative())->toBeFalse();
});

it('asks no alt text of a document', function () {
    $media = resolve(SaveMedia::class)->handle(fixture('media/brochure.pdf'));

    mediaPage($media)
        ->assertFormFieldHidden('alt')
        ->assertFormFieldHidden('decorative')
        ->fillForm(['caption' => 'Our brochure'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Media::query()->findOrFail($media->id)->caption())->toBe('Our brochure');
});

it('replaces the file within its family, generating the thumbnail after the response', function () {
    $media = resolve(SaveMedia::class)->handle(fixture('media/Holiday Photo.JPG'));

    mediaPage($media, role: ['kami.media.view', 'kami.media.update'])
        ->callAction('replace', ['file' => uploadedFixture('pixel.png')])
        ->assertHasNoActionErrors()
        ->assertNotified('File replaced');

    $replaced = Media::query()->findOrFail($media->id);

    expect($replaced->checksum)->toBe(hash_file('sha256', fixture('media/pixel.png')));

    app(DeferredCallbackCollection::class)->invoke();

    expect(Storage::disk('public')->exists(MediaResource::thumbnail($replaced)->path()))->toBeTrue();
});

it('refuses a replacement of another family on the file it was given', function () {
    $media = resolve(SaveMedia::class)->handle(fixture('media/Holiday Photo.JPG'));

    mediaPage($media)
        ->callAction('replace', ['file' => uploadedFixture('brochure.pdf')])
        ->assertHasActionErrors(['file']);

    expect(Media::query()->findOrFail($media->id)->checksum)->toBe($media->checksum);
});

it('takes kami.media.update to edit', function () {
    $media = resolve(SaveMedia::class)->handle(fixture('media/pixel.png'));

    mediaPage($media, role: ['kami.media.view'])->assertForbidden();
});

it('refuses a Locale the Site does not declare, and the default Locale\'s own segment', function (string $locale) {
    $media = resolve(SaveMedia::class)->handle(fixture('media/pixel.png'));

    mediaPage($media, $locale)->assertNotFound();
})->with(['de', 'en']);

describe('used by and delete', function () {
    beforeEach(function () {
        $this->media = resolve(SaveMedia::class)->handle(fixture('media/pixel.png'), ['alt' => 'A pixel']);
    });

    /** Projects using the Media item: Kami as its cover, Velox in its gallery and in the Trash. */
    function usedByProjects(Media $media): Entry
    {
        resolve(TrashEntry::class)->handle(resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Velox', 'gallery' => [$media->id]], locale: 'en'));

        return resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Kami', 'cover' => $media->id], locale: 'en');
    }

    it('lists the Entries using it, each linked to its edit page, those in the Trash marked and not linked', function () {
        $kami = usedByProjects($this->media);

        mediaPage($this->media)
            ->assertSeeHtml('href="' . url("admin/projects/{$kami->id}/edit") . '"')
            ->assertSee('Velox (in the Trash)')
            ->assertDontSeeHtml('>Velox (in the Trash)</a>');
    });

    it('names without a link an Entry the User cannot open, or of a type no longer declared', function () {
        usedByProjects($this->media);

        mediaPage($this->media, role: ['kami.media.view', 'kami.media.update'])
            ->assertSee('Kami')
            ->assertDontSeeHtml('/admin/projects/');

        // A type taken out of `kami.types`, its Entries left in the database.
        DB::table('kami_entries')->update(['type' => 'retired']);
        mediaPage($this->media)->assertSee('Kami')->assertDontSeeHtml('/admin/projects/');
    });

    it('lists the first 50 Entries, then how many more', function () {
        foreach (range(1, 51) as $number) {
            resolve(SaveEntry::class)->handle(Project::class, ['title' => "Project {$number}", 'cover' => $this->media->id], locale: 'en');
        }

        mediaPage($this->media)->assertSee('Project 50')->assertDontSee('Project 51')->assertSee('and 1 more');
    });

    it('says when no Entry uses it', function () {
        mediaPage($this->media)->assertSee('No Entry uses it.');
    });

    it('refuses to delete a Media item in use on a notification naming the Entries', function () {
        usedByProjects($this->media);

        mediaPage($this->media)
            ->callAction(DeleteAction::class)
            ->assertNotified(Notification::make()->danger()->title('Entries use this item: Velox (in the Trash), Kami. Remove it from them, or delete those in the Trash for good, first.'));

        expect(Media::query()->find($this->media->id))->not->toBeNull();
    });

    it('deletes an unused Media item, back to the library', function () {
        mediaPage($this->media, role: ['kami.media.view', 'kami.media.update', 'kami.media.delete'])
            ->callAction(DeleteAction::class)
            ->assertRedirect(MediaResource::getUrl('index'));

        expect(Media::query()->find($this->media->id))->toBeNull();
    });

    it('takes kami.media.delete to delete', function () {
        mediaPage($this->media, role: ['kami.media.view', 'kami.media.update'])->assertActionHidden(DeleteAction::class);
    });
});
