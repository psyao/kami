<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Psyao\Kami\Admin\Internal\EntryResource\EditEntry;
use Psyao\Kami\Content\Actions\RestoreEntry;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Actions\TrashEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Internal\PreviewLink;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Sites\Internal\Site;
use Workbench\App\Content\Article;
use Workbench\App\Content\Label;
use Workbench\App\Content\Project;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

function sharer(string $role = 'administrator'): User
{
    $user = User::forceCreate(['name' => 'Ada Lovelace', 'email' => uniqid() . '@example.test', 'password' => 'secret']);
    DB::table('kami_role_user')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->getKey(), 'role' => $role]);

    return $user;
}

/** @param  array<string, mixed>  $values */
function draftArticle(array $values = []): Entry
{
    return resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Brouillon', 'author' => 'Ada', ...$values]);
}

/** @param  array<string, mixed>  $parameters */
function linksPage(Entry $entry, array $parameters = [], string $type = 'article'): Testable
{
    Filament::setCurrentPanel('admin');
    Filament::setCurrentResourceConfigurationKey($type);

    return Livewire::test(EditEntry::class, ['record' => $entry->id, ...$parameters]);
}

beforeEach(function () {
    $this->freezeSecond();
    Kami::routes();
    $this->user = sharer();
    $this->actingAs($this->user);
});

it('creates a link expiring in 7 days by default, shown once and stored as a hash only', function () {
    $entry = draftArticle();

    $page = linksPage($entry)
        ->callAction('previewLinks')
        ->assertHasNoFormErrors()
        // In the page's state, not in a notification, which Filament's cache store would hold.
        ->assertActionMounted('previewLinkCreated')
        ->assertNotNotified();
    $url = $page->get('mountedActions')[0]['arguments']['url'];
    $token = Str::afterLast($url, '/');

    $link = PreviewLink::query()->sole();
    expect(strlen(hex2bin($token)))->toBe(32)
        ->and($link->token_hash)->toBe(hash('sha256', $token))
        ->and($link->expires_at->toDateTimeString())->toBe(now()->addDays(7)->toDateTimeString())
        ->and($link->locale)->toBe('fr')
        ->and($link->created_by)->toBe($this->user->getKey())
        ->and(DB::table('kami_preview_links')->get()->toJson())->not->toContain($token);
});

it('takes an expiry of 1 to 30 days, never more', function () {
    $entry = draftArticle();

    linksPage($entry)->callAction('previewLinks', ['days' => 31])->assertHasFormErrors(['days']);
    linksPage($entry)->callAction('previewLinks', ['days' => 0])->assertHasFormErrors(['days']);
    linksPage($entry)->callAction('previewLinks', ['days' => 30])->assertHasNoFormErrors();

    expect(PreviewLink::query()->sole()->expires_at->toDateTimeString())->toBe(now()->addDays(30)->toDateTimeString());
});

it('lists the Translation\'s active links with their expiry and Creator, and revokes one', function () {
    $entry = draftArticle();
    $kept = PreviewLink::issue($entry, 'fr', 3, $this->user);
    $revoked = PreviewLink::issue($entry, 'fr', 5, $this->user);
    PreviewLink::issue($entry, 'en', 5, $this->user);
    $this->travel(1)->days();

    $revokedId = PreviewLink::query()->where('token_hash', hash('sha256', Str::afterLast($revoked, '/')))->value('id');

    linksPage($entry)
        ->mountAction('previewLinks')
        ->assertMountedActionModalSee(['Expires on', 'created by Ada Lovelace', 'Revoke']);

    linksPage($entry)
        ->callAction([TestAction::make('previewLinks'), TestAction::make('revokePreviewLink')->arguments(['link' => $revokedId])])
        ->assertNotified();

    $this->get($revoked)->assertNotFound();
    $this->get($kept)->assertOk();
    expect(PreviewLink::query()->count())->toBe(2);
});

it('revokes only a link of the Entry and Locale edited', function () {
    $entry = draftArticle();
    $other = PreviewLink::issue(draftArticle(['title' => 'Autre']), 'fr', 7, $this->user);
    $otherId = PreviewLink::query()->sole()->id;

    linksPage($entry)->callAction([TestAction::make('previewLinks'), TestAction::make('revokePreviewLink')->arguments(['link' => $otherId])]);

    $this->get($other)->assertOk();
});

it('refuses a revocation without share-preview', function () {
    config(['kami.roles.reviewer' => ['kami.entries.*.view', 'kami.entries.*.update']]);
    $entry = draftArticle();
    $url = PreviewLink::issue($entry, 'fr', 7, $this->user);

    $this->actingAs(sharer('reviewer'));
    linksPage($entry)->callAction([TestAction::make('previewLinks'), TestAction::make('revokePreviewLink')->arguments(['link' => PreviewLink::query()->sole()->id])]);

    $this->get($url)->assertOk();
});

it('offers links under share-preview only, on a type with a URL', function () {
    config(['kami.roles.reviewer' => ['kami.entries.*.view', 'kami.entries.*.update']]);
    $entry = draftArticle();

    $this->actingAs(sharer('reviewer'));
    linksPage($entry)->assertActionHidden('previewLinks');

    $this->actingAs($this->user);
    linksPage($entry)->assertActionVisible('previewLinks');
    linksPage(resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Chantier']), type: 'project')->assertActionVisible('previewLinks');
});

it('shows the saved Draft in the link\'s Locale, with the banner and its expiry', function () {
    $entry = draftArticle();
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Draft', 'author' => 'Ada'], $entry, 'en');
    $url = PreviewLink::issue($entry, 'en', 7, $this->user);
    Auth::forgetGuards();

    $this->get($url)
        ->assertOk()
        ->assertSee('<html lang="en">', escape: false)
        ->assertSee('<h1>Draft</h1>', escape: false)
        ->assertSee('<aside class="kami-preview-banner" aria-label="Preview">', escape: false)
        // In the Site's time zone and the link's Locale.
        ->assertSee('This link expires on ' . now()->addDays(7)->setTimezone(Site::current()->timezone)->locale('en')->isoFormat('LLL') . '.');
});

it('reads an untranslatable type\'s Translation in the link\'s Locale', function () {
    $label = resolve(SaveEntry::class)->handle(Label::class, ['title' => 'Étiquette']);
    $url = PreviewLink::issue($label, 'en', 7, $this->user);

    $this->get($url)->assertOk()->assertSee('<html lang="en">', escape: false)->assertSee('Étiquette');
});

it('carries the front CSP, noindex, nofollow, no-store and no referrer, with no session cookie', function () {
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Public', 'author' => 'Ada', 'status' => 'published']);
    $url = PreviewLink::issue(draftArticle(), 'fr', 7, $this->user);
    $front = $this->get('/articles/public');

    $this->get($url)
        ->assertHeader('Content-Security-Policy', $front->headers->get('Content-Security-Policy'))
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeaderMissing('Set-Cookie');
});

it('answers a plain 404 to an unknown, expired or trashed link, and again 200 once restored', function () {
    $entry = draftArticle();
    $url = PreviewLink::issue($entry, 'fr', 1, $this->user);

    $this->get(Str::beforeLast($url, '/') . '/' . bin2hex(random_bytes(32)))->assertNotFound();

    resolve(TrashEntry::class)->handle($entry);
    $this->get($url)->assertNotFound();

    resolve(RestoreEntry::class)->handle(Entry::query()->withUnpublished()->withTrashed()->findOrFail($entry->id));
    $this->get($url)->assertOk();

    $this->travel(1)->days();
    $this->get($url)->assertNotFound();
});

it('lifts the scope for the linked Translation only, within an exact query count, under strict mode', function () {
    $visible = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Visible', 'author' => 'Ada', 'status' => 'published']);
    $draft = draftArticle(['title' => 'Cible en brouillon']);
    $project = resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Chantier', 'related' => [$visible->id, $draft->id]]);
    $url = PreviewLink::issue($project, 'fr', 7, $this->user);

    Model::shouldBeStrict();
    DB::enableQueryLog();
    $this->get($url)->assertOk()->assertSee('Chantier')->assertSee('Visible')->assertDontSee('Cible en brouillon');

    // The link; the Entry, its Translations and type rows; the snapshot's type rows and References; the visible targets, their Translations and type rows.
    expect(DB::getQueryLog())->toHaveCount(9);
});

it('prunes expired links only', function () {
    $entry = draftArticle();
    PreviewLink::issue($entry, 'fr', 1, $this->user);
    PreviewLink::issue($entry, 'fr', 2, $this->user);
    $this->travel(1)->days();

    $this->artisan('model:prune', ['--model' => PreviewLink::class])->assertSuccessful();

    expect(PreviewLink::query()->count())->toBe(1);
});
