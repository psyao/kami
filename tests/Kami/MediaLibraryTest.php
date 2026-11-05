<?php

declare(strict_types=1);

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Psyao\Kami\Admin\Internal\MediaResource;
use Psyao\Kami\Admin\Internal\MediaResource\ListMedia;
use Psyao\Kami\Media\Actions\SaveMedia;
use Psyao\Kami\Media\Media;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
});

/** The Media library in the admin, for a User holding the Role, or these Permissions under a Role of their own. */
function mediaLibrary(string | array $role = 'administrator'): Testable
{
    signInWith($role);
    Filament::setCurrentPanel('admin');

    return Livewire::test(ListMedia::class);
}

it('lists Media with their name, family, size and alt text state, filtered by family', function () {
    $save = resolve(SaveMedia::class);
    $photo = $save->handle(fixture('media/Holiday Photo.JPG'), ['alt' => 'Une plage']);
    $pixel = $save->handle(fixture('media/pixel.png'));
    $brochure = $save->handle(fixture('media/brochure.pdf'));

    mediaLibrary(['kami.media.view'])
        ->assertCanSeeTableRecords([$photo, $pixel, $brochure])
        ->assertTableColumnStateSet('alt', 'Described', $photo)
        ->assertTableColumnStateSet('alt', 'Missing', $pixel)
        ->assertTableColumnStateSet('alt', 'Not needed', $brochure)
        ->filterTable('family', 'document')
        ->assertCanSeeTableRecords([$brochure])
        ->assertCanNotSeeTableRecords([$photo, $pixel]);
});

/** A file as the browser sends it, named as the editor's own. */
function uploadedFixture(string $fixture, ?string $name = null): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name ?? basename($fixture), (string) file_get_contents(fixture("media/{$fixture}")));
}

it('saves each file of a multiple upload and reports a refused one with its reason, generating thumbnails after the response', function () {
    mediaLibrary(['kami.media.view', 'kami.media.create'])
        ->callAction('upload', ['files' => [uploadedFixture('Holiday Photo.JPG'), uploadedFixture('script.jpg', '<evil>.jpg'), uploadedFixture('brochure.pdf')]])
        ->assertHasNoFormErrors()
        ->assertNotified(Notification::make()->danger()->persistent()->title('1 file refused')
            ->body('<ul><li><strong>&lt;evil&gt;.jpg</strong>: Files of type text/x-php are not accepted.</li></ul>'));

    $photo = Media::query()->where('name', 'holiday-photo')->sole();
    $thumbnail = MediaResource::thumbnail($photo);

    expect(Media::query()->pluck('name')->sort()->values()->all())->toBe(['brochure', 'holiday-photo'])
        ->and(Storage::disk('public')->exists($thumbnail->path()))->toBeFalse();

    app(DeferredCallbackCollection::class)->invoke();

    expect(Storage::disk('public')->exists($thumbnail->path()))->toBeTrue();
});

it('reports a file that fails to save for another reason without saying why, logging it', function () {
    $path = Storage::disk('local')->path('media');
    File::ensureDirectoryExists(dirname($path));
    // A file where the originals' directory should be: writing an original there fails.
    File::put($path, '');
    Exceptions::fake();

    mediaLibrary()->callAction('upload', ['files' => [uploadedFixture('pixel.png')]])
        ->assertNotified(Notification::make()->danger()->persistent()->title('1 file refused')
            ->body('<ul><li><strong>pixel.png</strong>: This file could not be saved: try again, or tell the site&#039;s administrator.</li></ul>'));

    Exceptions::assertReportedCount(1);
    expect(Media::query()->count())->toBe(0);
});

it('confirms an upload with no refusal', function () {
    mediaLibrary(['kami.media.view', 'kami.media.create'])
        ->callAction('upload', ['files' => [uploadedFixture('pixel.png')]])
        ->assertNotified('1 file uploaded.');
});

it('takes kami.media.view to open the library and kami.media.create to upload', function () {
    signInWith(['kami.entries.*.view']);
    $this->get('/admin/media')->assertForbidden();

    mediaLibrary(['kami.media.view'])->assertActionHidden('upload');
    mediaLibrary(['kami.media.view', 'kami.media.create'])->assertActionVisible('upload');
});

it('limits each file to the lowest of PHP\'s and Livewire\'s upload limits', function () {
    config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:1024']]);

    mediaLibrary()->mountAction('upload')->assertFormFieldExists('files', fn (FileUpload $field): bool => $field->getMaxSize() === 1024);

    // Without a rule of Livewire's own, PHP's limit alone.
    config(['livewire.temporary_file_upload.rules' => ['required', 'file']]);
    mediaLibrary()->mountAction('upload')->assertFormFieldExists('files', fn (FileUpload $field): bool => $field->getMaxSize() === intdiv(UploadedFile::getMaxFilesize(), 1024));
});

it('reports a thumbnail that fails to generate, keeping the upload', function () {
    Exceptions::fake();
    failingToEncode('jpg', 'png');

    mediaLibrary()->callAction('upload', ['files' => [uploadedFixture('pixel.png')]])->assertNotified('1 file uploaded.');
    app(DeferredCallbackCollection::class)->invoke();

    expect(Media::query()->count())->toBe(1);
    Exceptions::assertReportedCount(1);
    failingToEncode();
});

it('renders the library and its upload form in English and French with no raw translation key', function (string $locale) {
    config(['app.locale' => $locale]);
    resolve(SaveMedia::class)->handle(fixture('media/pixel.png'));
    signInWith();

    expect((string) $this->get('/admin/media')->assertOk()->getContent())->not->toContain('kami::');

    // Filament renders an action's modal apart from the page.
    $raw = fn (?string $text): bool => $text !== null && ! str_contains($text, 'kami::');
    mediaLibrary()
        ->assertActionExists('upload', fn (Action $action): bool => $raw($action->getLabel()) && $raw($action->getModalSubmitAction()?->getLabel()))
        ->mountAction('upload')
        ->assertFormFieldExists('files', fn (FileUpload $field): bool => $raw($field->getLabel()));

    expect([trans_choice('kami::admin.media.upload.saved', 2), trans_choice('kami::admin.media.upload.refused', 1), __('kami::admin.media.upload.limits'), __('kami::admin.media.upload.failed')])
        ->each->not->toContain('kami::');
})->with(['en', 'fr']);
