<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Forms\Submission;
use Workbench\App\Content\Home;

uses(RefreshDatabase::class);

it('shows the form, then its error summary after a refused Submission, at axe\'s strictest level, light and dark', function (bool $dark) {
    resolve(SaveEntry::class)->handle(Home::class, ['title' => 'Accueil', 'status' => 'published'], locale: 'fr');
    $page = $dark ? visit('/')->inDarkMode() : visit('/');

    $page->assertPresent('form.kami-form')
        ->assertNoAccessibilityIssues(3)
        ->fill('name', 'Ada Lovelace')
        ->fill('email', 'ada@example.com')
        ->fill('message', 'Bonjour')
        ->press('Envoyer')
        ->assertPresent('.kami-form-errors[role="alert"] a[href="#message"]')
        ->assertValue('name', 'Ada Lovelace')
        ->assertNoAccessibilityIssues(3)
        ->assertNoJavaScriptErrors();
    expect(Submission::query()->count())->toBe(0);
})->with(['light' => false, 'dark' => true]);
