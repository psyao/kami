<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Media\Actions\SaveMedia;
use Workbench\App\Content\Project;

uses(RefreshDatabase::class);

it('shows a cover through x-kami::image at axe\'s strictest level', function (): void {
    Storage::fake('local');
    $cover = resolve(SaveMedia::class)->handle(fixture('media/Holiday Photo.JPG'), ['alt' => 'Un carré rouge']);
    resolve(SaveEntry::class)->handle(Project::class, ['title' => 'Refonte', 'status' => 'published', 'cover' => $cover->id], locale: 'fr');

    // The image itself is broken here: Conversion URLs use the Site URL, not the test server's host.
    visit('/project/refonte')
        ->assertPresent('picture img[alt="Un carré rouge"][width][height]')
        ->assertNoAccessibilityIssues(3)
        ->assertNoJavaScriptErrors();
});
