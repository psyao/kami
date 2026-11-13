<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;
use Psyao\Kami\Admin\Internal\AuditEventResource\ListAuditEvents;
use Psyao\Kami\Admin\Internal\EntryResource\ListEntries;
use Psyao\Kami\Admin\Internal\MediaResource\ListMedia;
use Psyao\Kami\Admin\Internal\SubmissionResource\ListSubmissions;
use Workbench\App\Forms\ContactForm;

uses(RefreshDatabase::class);

// Filament title-cases a Resource's label by default: "Audit Log", "Journal D’audit" (#387).
it('titles every Kami Resource as its label is translated, without title-casing it', function (string $locale, string $page, string $title) {
    config(['kami.forms' => [ContactForm::class]]);
    // A site's multi-word type label, which Filament would turn into "Blog Posts".
    Lang::addLines(['kami-types.article.plural' => $locale === 'fr' ? 'Billets du blog' : 'Blog posts'], $locale);
    app()->setLocale($locale);
    signInWith();
    Filament::setCurrentPanel('admin');
    Filament::setCurrentResourceConfigurationKey('article');

    expect(Livewire::test($page)->instance()->getTitle())->toBe($title);
})->with([
    ['en', ListAuditEvents::class, 'Audit log'],
    ['fr', ListAuditEvents::class, 'Journal d’audit'],
    ['en', ListSubmissions::class, 'Submissions'],
    ['fr', ListSubmissions::class, 'Messages reçus'],
    ['en', ListMedia::class, 'Media'],
    ['fr', ListMedia::class, 'Médias'],
    ['en', ListEntries::class, 'Blog posts'],
    ['fr', ListEntries::class, 'Billets du blog'],
]);
