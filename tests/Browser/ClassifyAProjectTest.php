<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Entry;
use Workbench\App\Content\Project;
use Workbench\App\Content\Tag;

uses(RefreshDatabase::class);

it('builds a page with a tab, a condition and a Term created from its picker, and reads it on the front', function () {
    signIn()
        ->navigate('/admin/projects/create')
        ->assertNoAccessibilityIssues()
        ->assertValue('[id="form.address.city"]', 'Vevey')
        ->fill('[id="form.title"]', 'Refonte')
        ->assertDontSee('Why it is featured')
        ->click('internal:role=switch[name="Featured"i]')
        ->fill('[id="form.featured_note"]', 'Projet phare')
        ->fill('[id="form.client"]', 'Acme')
        ->click('internal:role=tab[name="Story"s]')
        ->fill('[id="form.story"]', 'Une histoire')
        ->click('internal:role=tab[name="Details"s]')
        ->click('[aria-label="Create a Tag"]')
        ->fill('internal:role=dialog >> internal:role=textbox', 'Laravel')
        ->assertNoAccessibilityIssues()
        ->press('internal:role=dialog >> internal:role=button[name="Create"s]')
        ->assertSee('Laravel')
        ->press('internal:role=button[name="Publish"s]')
        ->assertSee('View on the site')
        ->assertNoJavaScriptErrors();

    $project = Entry::query()->ofType(Project::class)->sole();

    expect($project->tags->pluck('title')->all())->toBe(['Laravel']);

    foreach (['/admin/projects/create', "/admin/projects/{$project->id}/edit"] as $url) {
        visit($url)->inDarkMode()->assertDontSee('Sign in')->assertNoAccessibilityIssues()->assertNoJavaScriptErrors();
        visit($url)->on()->mobile()->assertDontSee('Sign in')->assertNoAccessibilityIssues()->assertNoJavaScriptErrors();
    }

    visit('/project/refonte')->assertSee('Laravel')->assertSee('Vevey')->assertNoJavaScriptErrors();
});

it('reorders a picker by dragging, and saves the order', function () {
    $save = resolve(SaveEntry::class);
    $first = $save->handle(Tag::class, ['title' => 'Premier']);
    $second = $save->handle(Tag::class, ['title' => 'Second']);
    $project = $save->handle(Project::class, ['title' => 'Refonte', 'tags' => [$first->id, $second->id]], locale: 'fr');

    signIn()
        ->navigate("/admin/projects/{$project->id}/edit")
        ->assertSee('Second')
        ->drag('.fi-select-input-value-badges-ctn [data-value]:has-text("Second")', '.fi-select-input-value-badges-ctn [data-value]:has-text("Premier")')
        ->press('internal:role=button[name="Save changes"s]')
        ->assertSee('Saved')
        ->assertNoJavaScriptErrors();

    expect(Entry::query()->withUnpublished()->findOrFail($project->id)->tags->pluck('title')->all())->toBe(['Second', 'Premier']);
});
