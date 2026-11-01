<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Psyao\Kami\Content\Actions\SaveEntry;
use Workbench\App\Content\Article;
use Workbench\App\Content\Project;

uses(RefreshDatabase::class);

it('names every link of a list holding a Draft', function () {
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Brouillon', 'author' => 'Ada'], locale: 'fr');

    signIn()
        ->navigate('/admin/articles')
        ->assertSee('Brouillon')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();
});

it('shows a site\'s own form and table', function () {
    resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Esquisse'], locale: 'fr');

    signIn()
        ->navigate('/admin/projects')
        ->assertSee('No client')
        ->assertNoAccessibilityIssues()
        ->navigate('/admin/projects/create')
        ->assertSee('Drafts stay private until published.')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();
});
