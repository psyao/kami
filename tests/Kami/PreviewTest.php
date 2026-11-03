<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Psyao\Kami\Admin\Internal\EntryResource\CreateEntry;
use Psyao\Kami\Admin\Internal\EntryResource\EditEntry;
use Psyao\Kami\Admin\Internal\RichTextInput;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Events\EntryPublished;
use Psyao\Kami\Content\Events\EntrySaved;
use Psyao\Kami\Content\Events\EntryUnpublished;
use Psyao\Kami\Content\Internal\Visibility;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Sites\Internal\Site;
use Workbench\App\Content\Article;
use Workbench\App\Content\Footer;
use Workbench\App\Content\Project;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

function previewer(string $role = 'administrator'): User
{
    $user = User::forceCreate(['name' => 'Ada Lovelace', 'email' => uniqid() . '@example.test', 'password' => 'secret']);
    DB::table('kami_role_user')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->getKey(), 'role' => $role]);

    return $user;
}

/** @param  array<string, mixed>  $parameters */
function previewPage(string $page, array $parameters = [], string $type = 'article'): Testable
{
    Filament::setCurrentPanel('admin');
    Filament::setCurrentResourceConfigurationKey($type);

    return Livewire::test($page, $parameters);
}

/** @param  array<string, mixed>  $values */
function previewedArticle(array $values = []): Entry
{
    return resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada', 'status' => 'published', ...$values]);
}

/** The address of the page's Preview, as `preview()` returns it. */
function previewUrl(Testable $page): string
{
    $url = url('/admin/kami/preview/' . $page->get('previewToken'));
    $page->assertReturned($url);

    return $url;
}

beforeEach(function () {
    config(['app.env' => 'local']);
    Kami::routes();
    $this->user = previewer();
    $this->actingAs($this->user);
});

it('renders the unsaved form through the type, saving nothing', function () {
    $entry = previewedArticle(['summary' => 'Avant.']);

    $page = previewPage(EditEntry::class, ['record' => $entry->id])
        ->fillForm(['title' => 'Bonjour encore', 'summary' => 'Pas encore enregistré.'])
        ->call('preview')
        ->assertHasNoFormErrors();

    $this->get(previewUrl($page))
        ->assertOk()
        ->assertSee('<h1>Bonjour encore</h1>', escape: false)
        ->assertSee('Pas encore enregistré.');

    expect(Entry::query()->sole()->summary)->toBe('Avant.');
});

it('runs none of the save\'s side effects', function () {
    $entry = previewedArticle();
    $updatedAt = $entry->updated_at?->toDateTimeString();
    Event::fake([EntrySaved::class, EntryPublished::class, EntryUnpublished::class]);
    $this->app->useStoragePath($storage = temporaryPath());
    $this->travel(5)->minutes();

    previewPage(EditEntry::class, ['record' => $entry->id])
        ->fillForm([
            // A skipped heading level, which a save reminds of.
            'body' => RichTextInput::toEditor(['type' => 'doc', 'content' => [['type' => 'heading', 'attrs' => ['level' => 4], 'content' => [['type' => 'text', 'text' => 'Plus bas']]]]]),
            // A date to come, which a save writes as the Site's next visibility change.
            'published_at' => now()->addDay()->setTimezone(Site::current()->timezone)->format('Y-m-d H:i:s'),
        ])
        ->call('preview')
        ->assertHasNoFormErrors()
        ->assertNotNotified();

    Event::assertNothingDispatched();
    expect(Entry::query()->sole()->updated_at?->toDateTimeString())->toBe($updatedAt)
        ->and(Visibility::nextChangeAt(Site::current()))->toBeNull()
        ->and(File::exists($storage))->toBeFalse();
});

it('shows an invalid value on the form, while a missing required one does not stop the Preview', function () {
    $entry = previewedArticle();

    previewPage(EditEntry::class, ['record' => $entry->id])
        ->fillForm(['summary' => str_repeat('a', 201)])
        ->call('preview')
        ->assertHasFormErrors(['summary'])
        ->assertReturned(null);

    // Published, so a save would require both.
    $page = previewPage(EditEntry::class, ['record' => $entry->id])
        ->fillForm(['title' => '', 'author' => ''])
        ->call('preview')
        ->assertHasNoFormErrors();

    $this->get(previewUrl($page))->assertOk();
});

it('previews a new Entry from the create page', function () {
    $page = previewPage(CreateEntry::class)
        ->fillForm(['title' => 'Tout neuf'])
        ->call('preview')
        ->assertHasNoFormErrors();

    $this->get(previewUrl($page))->assertOk()->assertSee('<h1>Tout neuf</h1>', escape: false);
    expect(Entry::query()->withUnpublished()->count())->toBe(0);
});

it('renders in the Locale edited', function () {
    $entry = previewedArticle();

    $page = previewPage(EditEntry::class, ['record' => $entry->id, 'locale' => 'en'])
        ->fillForm(['title' => 'Hello'])
        ->call('preview');

    $this->get(previewUrl($page))->assertOk()->assertSee('<html lang="en">', escape: false)->assertSee('<h1>Hello</h1>', escape: false);
});

it('keeps one token per page, each Preview replacing the last', function () {
    $page = previewPage(EditEntry::class, ['record' => previewedArticle()->id])
        ->fillForm(['title' => 'Premier'])
        ->call('preview');
    $url = previewUrl($page);

    $page->fillForm(['title' => 'Second'])->call('preview')->assertReturned($url);

    $this->get($url)->assertSee('<h1>Second</h1>', escape: false);
});

it('answers only the User who made it, within an hour', function () {
    $page = previewPage(EditEntry::class, ['record' => previewedArticle()->id])->call('preview');
    $url = previewUrl($page);

    $this->actingAs(previewer())->get($url)->assertNotFound();
    $this->get(url('/admin/kami/preview/unknown'))->assertNotFound();

    $this->travel(61)->minutes();
    $this->actingAs($this->user)->get($url)->assertNotFound();
});

it('takes the panel\'s sign-in and the type\'s view Permission', function () {
    $user = $this->user;
    $url = previewUrl(previewPage(EditEntry::class, ['record' => previewedArticle()->id])->call('preview'));

    Auth::forgetGuards();
    $this->get($url)->assertRedirect(url('/admin/login'));

    DB::table('kami_role_user')->where('user_id', $user->getKey())->update(['role' => 'nobody']);
    $this->actingAs($user->fresh())->get($url)->assertNotFound();
});

it('holds the snapshot in the file cache, not in the session', function () {
    config(['cache.default' => 'file']);
    $page = previewPage(EditEntry::class, ['record' => previewedArticle()->id])->fillForm(['title' => 'Sur disque'])->call('preview');

    expect(session()->all())->not->toHaveKey('kami.preview');
    $this->get(previewUrl($page))->assertSee('<h1>Sur disque</h1>', escape: false);
});

it('carries the front headers, noindex and no-store', function () {
    $url = previewUrl(previewPage(EditEntry::class, ['record' => previewedArticle()->id])->call('preview'));
    $front = $this->get('/articles/bonjour');

    $this->get($url)
        ->assertHeader('Content-Security-Policy', $front->headers->get('Content-Security-Policy'))
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
        ->assertHeader('X-Robots-Tag', 'noindex')
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('offers no Preview on a type with no URL', function () {
    previewPage(CreateEntry::class, type: 'testimonial')->assertActionHidden('preview');
});

it('offers no Preview on a Global Set', function () {
    resolve(SaveEntry::class)->handle(Footer::class, []);

    previewPage(EditEntry::class, type: 'footer')->assertActionHidden('preview');
});

it('leaves a Draft target out and renders within an exact query count, under strict mode', function () {
    $visible = previewedArticle(['title' => 'Visible']);
    $draft = previewedArticle(['title' => 'Brouillon', 'status' => 'draft']);
    $project = resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Chantier']);

    $page = previewPage(EditEntry::class, ['record' => $project->id], 'project')
        ->fillForm(['related' => [$visible->id, $draft->id]])
        ->call('preview')
        ->assertHasNoFormErrors();
    $url = previewUrl($page);

    Model::shouldBeStrict();
    DB::enableQueryLog();
    $this->get($url)->assertOk()->assertSee('Visible')->assertDontSee('Brouillon');

    // The visible targets, their Translations and type rows: the Preview itself comes from the cache.
    expect(DB::getQueryLog())->toHaveCount(3);
});

it('renders a rich text Preview with no query, under strict mode', function () {
    $page = previewPage(EditEntry::class, ['record' => previewedArticle()->id])
        ->fillForm(['body' => RichTextInput::toEditor(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Du texte riche']]]]])])
        ->call('preview');
    $url = previewUrl($page);

    Model::shouldBeStrict();
    DB::enableQueryLog();
    $this->get($url)->assertOk()->assertSee('<p>Du texte riche</p>', escape: false);

    // Everything comes from the cache.
    expect(DB::getQueryLog())->toBeEmpty();
});

it('shows the Preview banner in a Preview only', function () {
    $entry = previewedArticle();
    $url = previewUrl(previewPage(EditEntry::class, ['record' => $entry->id])->call('preview'));

    // In the Locale the Preview renders in.
    $this->get($url)->assertSee('<aside class="kami-preview-banner" aria-label="Prévisualisation">', escape: false);
    $this->get('/articles/bonjour')->assertOk()->assertDontSee('kami-preview-banner');
});
