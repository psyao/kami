<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Psyao\Kami\Admin\Internal\EntryResource\CreateEntry;
use Psyao\Kami\Admin\Internal\EntryResource\EditEntry;
use Psyao\Kami\Admin\Internal\EntryResource\ListEntries;
use Psyao\Kami\Admin\Internal\Widgets\NeedsAttention;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Media\Actions\SaveMedia;
use Psyao\Kami\Media\Media;
use Psyao\Kami\Support\Internal\Doctor\Doctor;
use Workbench\App\Content\Project;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

it('warns in kami:doctor with the count of images missing their alt text, which the dashboard then lists', function () {
    withConversionsServed();
    app()->useStoragePath($storage = temporaryPath());
    File::ensureDirectoryExists("{$storage}/framework/kami");
    $save = resolve(SaveMedia::class);
    $save->handle(fixture('media/pixel.png'));
    $save->handle(fixture('media/logo.svg'));
    $save->handle(fixture('media/pixel.png'), ['alt' => 'A pixel']);
    $save->handle(fixture('media/brochure.pdf'));

    $this->artisan('kami:doctor')
        ->expectsOutputToContain('2 images have no alt text.')
        ->expectsOutputToContain('docs/guide/media.md#alt-text-caption-and-credit')
        ->assertSuccessful();

    entriesPage(NeedsAttention::class, ['kami.health.view'], type: null)->assertSee('2 images have no alt text.');
});

it('says nothing of alt text once every image has one, or before the Media table exists', function () {
    withConversionsServed();
    resolve(SaveMedia::class)->handle(fixture('media/pixel.png'), ['decorative' => true]);

    expect((new Doctor)->problems())->toBeEmpty();

    config(['database.connections.empty' => ['driver' => 'sqlite', 'database' => ':memory:'], 'database.default' => 'empty']);

    expect((new Doctor)->problems())->toBeEmpty();
});

/** A Project showing an image without alt text, a described one, an SVG without alt text and a document. */
function illustrated(string $status = 'draft'): Entry
{
    $save = resolve(SaveMedia::class);
    $gallery = [
        $save->handle(fixture('media/Holiday Photo.JPG'), ['alt' => 'Une plage'])->id,
        $save->handle(fixture('media/logo.svg'))->id,
        $save->handle(fixture('media/brochure.pdf'))->id,
    ];

    return resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Kami', 'status' => $status, 'cover' => $save->handle(fixture('media/pixel.png'))->id, 'gallery' => $gallery]);
}

function missingAltWarning(string ...$names): Notification
{
    return Notification::make()->warning()->persistent()
        ->title(__('kami::admin.reminders.alt_title'))
        ->body('<p>' . e(__('kami::admin.reminders.alt_body')) . '</p><ul><li>' . implode('</li><li>', array_map(e(...), $names)) . '</li></ul>');
}

function publishAction(): TestAction
{
    return TestAction::make('publish')->schemaComponent('form-actions', schema: 'content');
}

it('warns, without stopping the save, of the images a published or scheduled Translation shows without alt text', function (string $status, Closure $act) {
    $project = illustrated($status);

    $act(entriesPage(EditEntry::class, parameters: ['record' => $project->id], type: 'project'))
        ->assertHasNoFormErrors()
        ->assertNotified(missingAltWarning('logo', 'pixel'));

    expect(Entry::query()->withUnpublished()->ofType('project')->sole()->status)->toBe('published');
})->with([
    'published' => ['draft', fn ($page) => $page->callAction(publishAction())],
    'scheduled' => ['draft', fn ($page) => $page->fillForm(['published_at' => now()->addDay()->toDateTimeString()])->callAction(publishAction())],
    'saved while published' => ['published', fn ($page) => $page->call('save')],
]);

it('never warns of alt text on a Draft, or when every image shown is described', function () {
    $draft = illustrated();

    entriesPage(EditEntry::class, parameters: ['record' => $draft->id], type: 'project')
        ->call('save')
        ->assertNotNotified(__('kami::admin.reminders.alt_title'));

    Media::query()->missingAlt()->get()->each(fn (Media $media) => resolve(SaveMedia::class)->handle(null, ['alt' => 'Décrite'], $media));

    entriesPage(EditEntry::class, parameters: ['record' => $draft->id], type: 'project')
        ->callAction(publishAction())
        ->assertNotNotified(__('kami::admin.reminders.alt_title'));
});

it('warns on the create page\'s Publish, in French', function () {
    app()->setLocale('fr');
    $pixel = resolve(SaveMedia::class)->handle(fixture('media/pixel.png'));

    entriesPage(CreateEntry::class, type: 'project')
        ->fillForm(['title' => 'Kami', 'cover' => $pixel->id])
        ->callAction(publishAction())
        ->assertHasNoFormErrors()
        ->assertNotified(missingAltWarning('pixel'));

    expect(__('kami::admin.reminders.alt_title'))->not->toBe('Images without alt text');
});

it('warns once on a bulk Publish, of the images every row it published shows without alt text', function () {
    $project = fn (string $title, string $image, string $status = 'draft'): Entry => resolve(SaveEntry::class)
        ->handle(Project::class, ['title' => $title, 'status' => $status, 'cover' => resolve(SaveMedia::class)->handle(uploadedFixture('pixel.png', "{$image}.png"))->id]);
    $first = illustrated();
    $second = $project('Vevey', 'quay');
    $refused = $project('Montreux', 'castle', 'published');

    entriesPage(ListEntries::class, type: 'project')
        ->selectTableRecords([$first->id, $second->id, $refused->id])
        ->callAction(TestAction::make('publish')->table()->bulk())
        ->assertNotified(missingAltWarning('logo', 'pixel', 'quay'));
});
