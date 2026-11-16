<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Psyao\Kami\Content\Actions\SaveEntry;
use Workbench\App\Content\Article;

uses(RefreshDatabase::class);

it('inserts a Block from the rich editor\'s Blocks button', function () {
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada']);

    signIn()->navigate("/admin/articles/{$entry->id}/edit")
        ->click('button[aria-label="Blocks"]')
        ->click('Callout')
        ->fill('[id="mountedActionSchema0.text"]', 'Une note')
        ->press('Insert')
        ->assertSee('Une note')
        ->press('Save changes')
        ->assertSee('Saved')
        ->assertNoJavaScriptErrors();

    expect(DB::table('kami_type_article')->where(['entry_id' => $entry->id, 'locale' => 'fr'])->value('body'))
        ->toBe('{"type":"doc","content":[{"type":"block","attrs":{"handle":"callout","data":{"text":"Une note","tone":null,"image":null}}}]}');
});
