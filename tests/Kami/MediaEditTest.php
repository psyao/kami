<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Psyao\Kami\Admin\Internal\MediaResource;
use Psyao\Kami\Admin\Internal\MediaResource\EditMedia;
use Psyao\Kami\Media\Actions\SaveMedia;
use Psyao\Kami\Media\Media;

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
