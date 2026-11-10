<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Psyao\Kami\Content\Actions\SaveEntry;
use Workbench\App\Content\Article;

uses(RefreshDatabase::class);

it('names every part of the dashboard\'s widgets, each loaded after the page', function () {
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Brouillon', 'author' => 'Ada'], locale: 'fr');
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'À paraître', 'author' => 'Ada', 'status' => 'published', 'published_at' => now()->addDay()], locale: 'fr');

    assertAxeClean(signIn()
        ->assertSee('Brouillon')
        ->assertSee('À paraître')
        ->assertSee('Needs attention'), admin: true)
        ->assertNoJavaScriptErrors();
});
