<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Psyao\Kami\Users\Actions\AssignRoles;
use Workbench\App\Models\User;

uses(RefreshDatabase::class);

it('lists Users with their Roles and creates one with the Roles field and its one-time link, in light, dark and mobile', function () {
    $grace = User::withoutEvents(fn () => User::forceCreate(['name' => 'Grace Hopper', 'email' => 'grace@example.test', 'password' => Hash::make('secret')]));
    resolve(AssignRoles::class)->handle($grace, ['editor']);

    $page = assertAxeClean(signIn()
        ->navigate('/admin/users')
        ->assertSee('Grace Hopper')
        ->assertSee('Editor'), admin: true);
    $page = assertAxeClean($page
        ->navigate("/admin/users/{$grace->getKey()}/edit")
        ->assertSee('Roles')
        ->assertSee('Administrator'), admin: true);
    assertAxeClean($page
        ->navigate('/admin/users/create')
        ->fill('[id="form.name"]', 'Linus')
        ->fill('[id="form.email"]', 'linus@example.test')
        ->check('Editor')
        ->press('internal:role=button[name="Create"s]')
        ->assertSee('Send Linus this link to set their password'), admin: true)
        ->assertNoJavaScriptErrors();

    assertAxeClean(visit('/admin/users/create')->inDarkMode()->assertSee('Roles'), admin: true)->assertNoJavaScriptErrors();
    assertAxeClean(visit('/admin/users')->on()->mobile()->assertSee('Grace Hopper'), admin: true)->assertNoJavaScriptErrors();
});
