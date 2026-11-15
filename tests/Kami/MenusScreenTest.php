<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Psyao\Kami\Admin\Internal\MenuItemResource\ManageMenuItems;
use Psyao\Kami\Audit\Internal\AuditEvent;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Menus\Actions\SaveMenuItem;
use Psyao\Kami\Menus\MenuItem;
use Workbench\App\Content\Page;

uses(RefreshDatabase::class);

// The Workbench declares `primary` at depth 2 and `footer` at depth 1: the panel registers its pages at boot.

/** @param array<string, mixed> $values */
function screenItem(array $values, string $menu = 'primary'): MenuItem
{
    return resolve(SaveMenuItem::class)->handle($menu, $values);
}

function screenPage(string $title): Entry
{
    return resolve(SaveEntry::class)->handle(Page::class, ['title' => $title, 'status' => 'published'], null, 'fr');
}

function menuScreen(string $menu = 'primary', string | array $role = 'administrator'): Testable
{
    signInWith($role);
    Filament::setCurrentPanel('admin');
    Filament::setCurrentResourceConfigurationKey($menu);

    return Livewire::test(ManageMenuItems::class);
}

/** @return list<string> The root's labels, or a Parent's, in their order. */
function screenOrder(?MenuItem $parent = null): array
{
    return MenuItem::query()->where('menu', 'primary')->where('parent_id', $parent?->id)->orderBy('position')->get()
        ->map(fn (MenuItem $item): string => $item->label['fr'])->all();
}

it('turns away a User without kami.menus.manage, and shows them no Menu in the navigation', function (): void {
    signInWith(['kami.media.view']);

    $this->get('/admin/menus/primary')->assertForbidden();
    $this->get('/admin')->assertOk()->assertDontSee('/admin/menus/', false);
});

it('gives the Workbench editor, who holds kami.menus.manage, one page and navigation item per declared Menu', function (): void {
    signInWith('editor');

    $this->get('/admin/menus/primary')->assertOk()->assertSee('Main menu');
    $this->get('/admin/menus/footer')->assertOk()->assertSee('Footer menu');
    $this->get('/admin/menus/sidebar')->assertNotFound();
    $this->get('/admin')->assertSee('/admin/menus/primary', false)->assertSee('/admin/menus/footer', false);
});

it('lists the whole tree in tree order, indented by level, labels falling back to the target\'s title, this Menu and Site only', function (): void {
    $about = screenPage('À propos');
    $first = screenItem(['url' => '/a', 'label' => ['fr' => 'Premier']]);
    $parent = screenItem(['entry' => $about]);
    $child = screenItem(['parent' => $parent->id, 'url' => '/c', 'label' => ['fr' => 'Enfant']]);
    $last = screenItem(['archive' => 'article', 'label' => ['fr' => 'Articles']]);
    $footer = screenItem(['url' => '/f', 'label' => ['fr' => 'Pied']], 'footer');
    $other = MenuItem::query()->create(['site' => 'blog', 'menu' => 'primary', 'position' => 1, 'url' => ['en' => '/x'], 'label' => ['en' => 'Elsewhere'], 'new_tab' => false]);

    menuScreen()
        ->assertCanSeeTableRecords([$first, $parent, $child, $last], inOrder: true)
        ->assertCanNotSeeTableRecords([$footer, $other])
        ->assertTableColumnFormattedStateSet('label', 'À propos', $parent)
        ->assertSeeHtml('kami-menu-level-2')
        ->assertSee(['Level 2', '/c']);
});

it('creates an Entry, an Archive and an address item through SaveMenuItem, each Locale\'s label and address at once', function (): void {
    $about = screenPage('À propos');

    menuScreen(role: 'editor')
        ->callAction('create', ['kind' => 'entry', 'entry' => $about->id, 'label' => ['fr' => 'Qui', 'en' => 'Who']])
        ->assertHasNoActionErrors()
        ->callAction('create', ['kind' => 'archive', 'archive' => 'article'])
        ->assertHasNoActionErrors()
        ->callAction('create', ['kind' => 'url', 'url' => ['fr' => '/contact', 'en' => '/en/contact'], 'label' => ['fr' => 'Contact', 'en' => 'Contact'], 'new_tab' => true])
        ->assertHasNoActionErrors();

    $items = MenuItem::query()->where('menu', 'primary')->orderBy('position')->get();
    // Equal, not identical: MySQL's JSON columns keep their keys in an order of their own.
    expect($items->map->only(['entry_id', 'archive', 'url', 'label', 'new_tab'])->all())->toEqual([
        ['entry_id' => $about->id, 'archive' => null, 'url' => null, 'label' => ['fr' => 'Qui', 'en' => 'Who'], 'new_tab' => false],
        ['entry_id' => null, 'archive' => 'article', 'url' => null, 'label' => null, 'new_tab' => false],
        ['entry_id' => null, 'archive' => null, 'url' => ['fr' => '/contact', 'en' => '/en/contact'], 'label' => ['fr' => 'Contact', 'en' => 'Contact'], 'new_tab' => true],
    ])
        ->and(AuditEvent::query()->where('event', 'kami.menu-items.created')->count())->toBe(3);
});

it('shows SaveMenuItem\'s refusals on the form\'s inputs', function (array $data, string $input): void {
    $parent = screenItem(['url' => '/p', 'label' => ['fr' => 'Parent']]);
    $child = screenItem(['parent' => $parent->id, 'url' => '/c', 'label' => ['fr' => 'Enfant']]);
    // The dataset names the child, created here.
    $data = array_map(fn (mixed $value): mixed => $value === 'CHILD' ? $child->id : $value, $data);

    menuScreen()->callAction('create', $data)->assertHasActionErrors([$input]);

    expect(MenuItem::query()->count())->toBe(2);
})->with([
    'no Entry picked' => [['kind' => 'entry'], 'entry'],
    'no Archive picked' => [['kind' => 'archive'], 'archive'],
    'an address not allowed' => [['kind' => 'url', 'url' => ['fr' => '/ok', 'en' => 'javascript:alert(1)'], 'label' => ['fr' => 'X', 'en' => 'X']], 'url.en'],
    'no address in the default Locale' => [['kind' => 'url', 'url' => ['en' => '/en'], 'label' => ['en' => 'X']], 'url.fr'],
    'deeper than the Menu\'s depth' => [['kind' => 'url', 'url' => ['fr' => '/d'], 'label' => ['fr' => 'D'], 'parent' => 'CHILD'], 'parent'],
]);

it('edits an item: a new target clears the old one, a cleared Locale label is removed, a new Parent puts it last there', function (): void {
    $about = screenPage('À propos');
    $parent = screenItem(['url' => '/p', 'label' => ['fr' => 'Parent']]);
    screenItem(['parent' => $parent->id, 'url' => '/c', 'label' => ['fr' => 'Enfant']]);
    $item = screenItem(['url' => ['fr' => '/x', 'en' => '/en/x'], 'label' => ['fr' => 'X', 'en' => 'X']]);

    menuScreen()
        ->mountAction(TestAction::make('edit')->table($item))
        ->assertActionDataSet(['kind' => 'url', 'url' => ['fr' => '/x', 'en' => '/en/x'], 'label' => ['fr' => 'X', 'en' => 'X'], 'parent' => null])
        ->setActionData(['kind' => 'entry', 'entry' => $about->id, 'label' => ['fr' => 'Qui', 'en' => null], 'parent' => $parent->id])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($item->refresh()->only(['entry_id', 'url', 'label', 'parent_id', 'position']))
        ->toBe(['entry_id' => $about->id, 'url' => null, 'label' => ['fr' => 'Qui'], 'parent_id' => $parent->id, 'position' => 2]);
});

it('offers as Parent the Menu\'s items but the item itself and its subtree, and no Parent in a single-level Menu', function (): void {
    $parent = screenItem(['url' => '/p', 'label' => ['fr' => 'Parent']]);
    $child = screenItem(['parent' => $parent->id, 'url' => '/c', 'label' => ['fr' => 'Enfant']]);
    $other = screenItem(['url' => '/o', 'label' => ['fr' => 'Autre']]);
    $footer = screenItem(['url' => '/f', 'label' => ['fr' => 'Pied']], 'footer');

    menuScreen()
        ->mountAction(TestAction::make('edit')->table($parent))
        ->assertFormFieldExists('parent', 'mountedActionSchema0', fn ($field): bool => array_keys($field->getOptions()) === [$other->id]);

    menuScreen('footer')
        ->mountAction(TestAction::make('edit')->table($footer))
        ->assertFormFieldHidden('parent', 'mountedActionSchema0');
});

it('moves an item up and down among its siblings through MoveMenuItem, never past either end', function (): void {
    $a = screenItem(['url' => '/a', 'label' => ['fr' => 'A']]);
    $b = screenItem(['url' => '/b', 'label' => ['fr' => 'B']]);
    $c = screenItem(['url' => '/c', 'label' => ['fr' => 'C']]);

    menuScreen('primary', 'editor')
        ->assertTableActionDisabled('up', $a)
        ->assertTableActionDisabled('down', $c)
        ->callTableAction('down', $a)
        ->callTableAction('up', $c);

    expect(screenOrder())->toBe(['B', 'C', 'A'])
        ->and(AuditEvent::query()->where('event', 'kami.menu-items.moved')->count())->toBe(2);
});

it('deletes an item after confirmation, its children moving up one level in its place', function (): void {
    $a = screenItem(['url' => '/a', 'label' => ['fr' => 'A']]);
    $parent = screenItem(['url' => '/p', 'label' => ['fr' => 'Parent']]);
    screenItem(['parent' => $parent->id, 'url' => '/c', 'label' => ['fr' => 'Enfant']]);
    screenItem(['url' => '/z', 'label' => ['fr' => 'Z']]);

    menuScreen()
        ->mountAction(TestAction::make('delete')->table($parent))
        ->assertMountedActionModalSee('Its children move up one level')
        ->callMountedAction();

    expect(screenOrder())->toBe(['A', 'Enfant', 'Z'])
        ->and(AuditEvent::query()->where('event', 'kami.menu-items.deleted')->count())->toBe(1);
});
