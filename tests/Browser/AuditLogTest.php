<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Psyao\Kami\Audit\Actions\RecordAuditEvent;
use Psyao\Kami\Content\Actions\SaveEntry;
use Workbench\App\Content\Article;

uses(RefreshDatabase::class);

it('reads the Audit log, its filters open, in light, dark and mobile', function () {
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada', 'status' => 'published'], locale: 'fr');
    resolve(RecordAuditEvent::class)->handle('acme.invoices.exported', $entry, ['number' => 'F-12']);

    $page = assertAxeClean(signIn()
        ->navigate('/admin/audit-events')
        ->assertSee('Entry published')
        ->assertSee('Entry: Bonjour'), admin: true);
    assertAxeClean($page
        ->click('internal:role=button[name="Filter"i]')
        ->assertSee('Until'), admin: true)
        ->assertNoJavaScriptErrors();

    assertAxeClean(visit('/admin/audit-events')->inDarkMode()->assertSee('Entry published'), admin: true)->assertNoJavaScriptErrors();
    assertAxeClean(visit('/admin/audit-events')->on()->mobile()->assertSee('Entry published'), admin: true)->assertNoJavaScriptErrors();
});
