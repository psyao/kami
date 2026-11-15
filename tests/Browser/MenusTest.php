<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Psyao\Kami\Menus\Actions\SaveMenuItem;
use Psyao\Kami\Menus\MenuItem;

uses(RefreshDatabase::class);

it('moves an item with Tab and Enter alone, focus kept on its control, the page and its modal clean under axe', function () {
    $save = resolve(SaveMenuItem::class);
    $parent = $save->handle('primary', ['url' => '/a', 'label' => ['fr' => 'Premier', 'en' => 'First']]);
    $save->handle('primary', ['parent' => $parent->id, 'url' => '/a/b', 'label' => ['fr' => 'Enfant', 'en' => 'Child']]);
    $save->handle('primary', ['url' => '/c', 'label' => ['fr' => 'Dernier', 'en' => 'Last']]);

    $focused = "document.activeElement.getAttribute('aria-label') ?? document.activeElement.textContent.trim()";
    $page = assertAxeClean(signIn()->navigate('/admin/menus/primary')->assertSee('Enfant'), admin: true);

    // From the New button, Tab alone reaches the move controls (WCAG 2.1.1); the loop stops if focus leaves the page.
    $page->keys('internal:role=button[name="New menu item"i]', 'Tab');

    for ($i = 0; $i < 40 && $page->script('document.activeElement !== document.body') && $page->script($focused) !== 'Move Dernier up'; $i++) {
        $page->keys(':focus', 'Tab');
    }

    $page->assertScript($focused, 'Move Dernier up');
    $page->keys(':focus', 'Enter');
    $page->wait(1);

    expect(MenuItem::query()->whereNull('parent_id')->orderBy('position')->get()->map(fn (MenuItem $item): string => $item->label['fr'])->all())->toBe(['Dernier', 'Premier']);
    // The row moved, its control still holds focus (WCAG 2.4.3); at the top, its other one does.
    $page->assertScript($focused, 'Move Dernier down');

    $page->click('internal:role=button[name="New menu item"i]')->assertSee('Opens in a new tab');
    // Once the modal's opening transition ends: axe would read the page behind it half-faded.
    $page->wait(1);
    assertAxeClean($page, admin: true)
        ->assertNoJavaScriptErrors();
});
