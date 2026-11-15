<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Psyao\Kami\Admin\Internal\RedirectResource\ManageRedirects;
use Psyao\Kami\Audit\Internal\AuditEvent;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Redirects\Actions\SaveRedirect;
use Psyao\Kami\Redirects\Redirect;
use Workbench\App\Content\Page;

uses(RefreshDatabase::class);

function redirectScreenPage(string $title): Entry
{
    return resolve(SaveEntry::class)->handle(Page::class, ['title' => $title, 'status' => 'published'], null, 'fr');
}

function redirectScreen(string | array $role = 'administrator'): Testable
{
    signInWith($role);
    Filament::setCurrentPanel('admin');

    return Livewire::test(ManageRedirects::class);
}

it('turns away a User without kami.redirects.manage, the Workbench editor included, and shows them no Redirects in the navigation', function (): void {
    signInWith('editor');

    $this->get('/admin/redirects')->assertForbidden();
    $this->get('/admin')->assertOk()->assertDontSee('/admin/redirects', false);
});

it('gives a User holding kami.redirects.manage the page and its navigation item', function (): void {
    signInWith(['kami.redirects.manage']);

    $this->get('/admin/redirects')->assertOk()->assertSee('Redirects');
    $this->get('/admin')->assertSee('/admin/redirects', false);
});

it('lists the Site\'s Redirects, Kami\'s own included, with their target\'s title or address and an empty date for rows written before 0.10.0', function (): void {
    $about = redirectScreenPage('À propos');
    $save = resolve(SaveRedirect::class);
    $toEntry = $save->handle('/?p=2', 301, $about);
    $toAddress = $save->handle('/blog', 302, '/articles', 'en');
    $gone = $save->handle('/?attachment_id', 410);
    // As Kami wrote a moved Path's 301 before 0.10.0: no timestamps.
    DB::table('kami_paths')->insert(['id' => $id = strtolower((string) Str::ulid()), 'site' => 'default', 'locale' => 'fr', 'path' => 'ancienne', 'entry_id' => $about->id, 'status' => 301]);
    $moved = Redirect::query()->findOrFail($id);
    $other = Redirect::query()->create(['site' => 'blog', 'locale' => 'en', 'path' => 'x', 'status' => 410]);

    redirectScreen()
        ->assertCanSeeTableRecords([$toEntry, $toAddress, $gone, $moved])
        // Nor the Entry's current Path, a row of the same table.
        ->assertCanNotSeeTableRecords([$other, DB::table('kami_paths')->where('status', 200)->value('id')])
        ->assertTableColumnFormattedStateSet('path', '/?p=2', $toEntry)
        ->assertTableColumnFormattedStateSet('path', '/blog', $toAddress)
        ->assertTableColumnStateSet('locale', 'en', $toAddress)
        ->assertTableColumnFormattedStateSet('target', 'À propos', $toEntry)
        ->assertTableColumnFormattedStateSet('target', '/articles', $toAddress)
        ->assertTableColumnFormattedStateNotSet('target', 'À propos', $gone)
        ->assertTableColumnStateNotSet('updated_at', 'Unknown', $toEntry)
        ->assertTableColumnStateSet('updated_at', 'Unknown', $moved);
});

it('searches by source, its leading slash optional, and filters by status', function (): void {
    $save = resolve(SaveRedirect::class);
    $blog = $save->handle('/blog', 301, '/articles');
    $news = $save->handle('/news', 302, '/articles');
    $gone = $save->handle('/?attachment_id', 410);

    redirectScreen()
        ->searchTable('/blo')
        ->assertCanSeeTableRecords([$blog])
        ->assertCanNotSeeTableRecords([$news, $gone])
        // `_` is no wildcard: it matches the underscore alone.
        ->searchTable('_')
        ->assertCanSeeTableRecords([$gone])
        ->assertCanNotSeeTableRecords([$blog, $news])
        ->searchTable('news')
        ->assertCanSeeTableRecords([$news])
        ->assertCanNotSeeTableRecords([$blog])
        ->searchTable(null)
        ->filterTable('status', 410)
        ->assertCanSeeTableRecords([$gone])
        ->assertCanNotSeeTableRecords([$blog, $news]);
});

it('creates Redirects to an Entry, to an address and 410 through SaveRedirect, in the Locale chosen', function (): void {
    $about = redirectScreenPage('À propos');

    redirectScreen()
        ->callAction('create', ['source' => '/?p=2', 'status' => 301, 'kind' => 'entry', 'entry' => $about->id])
        ->assertHasNoActionErrors()
        ->callAction('create', ['source' => '/Press/', 'locale' => 'en', 'status' => 302, 'kind' => 'url', 'target' => 'https://example.com/press'])
        ->assertHasNoActionErrors()
        ->callAction('create', ['source' => '/?attachment_id', 'status' => 410, 'kind' => 'url', 'target' => '/ignored'])
        ->assertHasNoActionErrors();

    expect(Redirect::query()->orderBy('locale')->orderBy('path')->get()->map->only(['locale', 'path', 'status', 'entry_id', 'target'])->all())->toBe([
        ['locale' => 'en', 'path' => 'press', 'status' => 302, 'entry_id' => null, 'target' => 'https://example.com/press'],
        ['locale' => 'fr', 'path' => '?attachment_id', 'status' => 410, 'entry_id' => null, 'target' => null],
        ['locale' => 'fr', 'path' => '?p=2', 'status' => 301, 'entry_id' => $about->id, 'target' => null],
    ])
        ->and(AuditEvent::query()->where('event', 'kami.redirects.created')->count())->toBe(3);
});

it('shows refusals on the form\'s inputs, SaveRedirect\'s and an undeclared Locale', function (array $data, string $input): void {
    $live = redirectScreenPage('À propos');
    resolve(SaveRedirect::class)->handle('/old', 301, '/new');
    $data = array_map(fn (mixed $value): mixed => $value === 'LIVE' ? $live->id : $value, $data);

    redirectScreen()->callAction('create', [...['source' => '/somewhere', 'status' => 301, 'kind' => 'url'], ...$data])->assertHasActionErrors([$input]);

    expect(Redirect::query()->count())->toBe(1);
})->with([
    'a source Kami answers itself' => [['source' => '/media/x', 'target' => '/x'], 'source'],
    'an Entry\'s current address' => [['source' => '/a-propos', 'target' => '/x'], 'source'],
    'no Entry picked' => [['kind' => 'entry'], 'entry'],
    'no address' => [[], 'target'],
    'an address not allowed' => [['target' => 'javascript:alert(1)'], 'target'],
    'a target that redirects itself' => [['target' => '/old'], 'target'],
    'an undeclared Locale' => [['locale' => 'de', 'target' => '/x'], 'locale'],
]);

it('edits a Redirect\'s status and target, its source and Locale kept, Kami\'s own rows included', function (): void {
    $about = redirectScreenPage('À propos');
    DB::table('kami_paths')->insert(['id' => $id = strtolower((string) Str::ulid()), 'site' => 'default', 'locale' => 'fr', 'path' => 'ancienne', 'entry_id' => $about->id, 'status' => 301]);
    $moved = Redirect::query()->findOrFail($id);

    redirectScreen()
        ->mountAction(TestAction::make('edit')->table($moved))
        ->assertActionDataSet(['source' => '/ancienne', 'locale' => 'fr', 'status' => 301, 'kind' => 'entry', 'entry' => $about->id])
        // Disabled inputs: a value posted for them is not read.
        ->setActionData(['source' => '/elsewhere', 'locale' => 'en', 'status' => 302, 'kind' => 'url', 'target' => '/a-propos'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(Redirect::query()->sole()->only(['id', 'locale', 'path', 'status', 'entry_id', 'target']))
        ->toBe(['id' => $id, 'locale' => 'fr', 'path' => 'ancienne', 'status' => 302, 'entry_id' => null, 'target' => '/a-propos'])
        ->and(AuditEvent::query()->where('event', 'kami.redirects.updated')->count())->toBe(1);
});

it('deletes a Redirect through DeleteRedirect, after a confirmation', function (): void {
    $redirect = resolve(SaveRedirect::class)->handle('/blog', 301, '/articles');

    redirectScreen()
        ->mountAction(TestAction::make('delete')->table($redirect))
        ->assertActionMounted(TestAction::make('delete')->table($redirect))
        ->callMountedAction();

    expect(Redirect::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('event', 'kami.redirects.deleted')->count())->toBe(1);
});
