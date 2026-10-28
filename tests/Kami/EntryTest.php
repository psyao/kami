<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Facades\Kami;
use Workbench\App\Content\Article;

uses(RefreshDatabase::class);

/** @param array<string, mixed> $values */
function article(array $values): Entry
{
    return resolve(SaveEntry::class)->handle(Article::class, $values);
}

it('hides Drafts and Scheduled Entries unless asked for them', function () {
    $published = article(['title' => 'Published', 'status' => 'published', 'published_at' => now()->subMinute()]);
    article(['title' => 'Draft']);
    article(['title' => 'Scheduled', 'status' => 'published', 'published_at' => now()->addHour()]);

    expect(Entry::query()->pluck('id')->all())->toBe([$published->id])
        ->and(Entry::query()->ofType(Article::class)->count())->toBe(1)
        ->and(Entry::query()->ofType('article')->count())->toBe(1)
        ->and(Entry::query()->withUnpublished()->count())->toBe(3);
});

it('saves a Draft by default and dates a publication with no date now', function () {
    $this->freezeSecond();

    $draft = article(['title' => 'Draft']);
    $published = article(['title' => 'Now', 'status' => 'published']);

    expect($draft->status)->toBe('draft')
        ->and($draft->published_at)->toBeNull()
        ->and($published->status)->toBe('published')
        ->and($published->published_at?->toIso8601String())->toBe(now()->toIso8601String());
});

it('updates the Translation, Field values and Path of an existing Entry', function () {
    $entry = article(['title' => 'First', 'summary' => 'One', 'status' => 'published']);

    $updated = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Second', 'slug' => 'second', 'summary' => 'Two'], $entry);

    expect($updated->id)->toBe($entry->id)
        ->and(Entry::query()->withUnpublished()->count())->toBe(1)
        ->and([$updated->title, $updated->slug, $updated->summary, $updated->status])->toBe(['Second', 'second', 'Two', 'published']);

    Kami::routes();
    $this->get('/articles/first')->assertNotFound();
    $this->get('/articles/second')->assertOk();
});

it('loads Translations and Field values with a collection, never lazily', function () {
    article(['title' => 'One', 'summary' => 'First', 'status' => 'published']);
    article(['title' => 'Two', 'summary' => 'Second', 'status' => 'published']);

    $entries = Entry::query()->orderBy('id')->get();

    expect($entries->map(fn (Entry $entry) => [$entry->title, $entry->summary])->all())->toBe([['One', 'First'], ['Two', 'Second']]);
});
