<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Events\EntryPublished;
use Psyao\Kami\Content\Events\EntrySaved;
use Psyao\Kami\Content\Events\EntryUnpublished;
use Workbench\App\Content\Article;
use Workbench\App\Content\Label;

uses(RefreshDatabase::class);

/**
 * Records the Content events dispatched, by class basename, through real listeners.
 *
 * @return ArrayObject<int, object>
 */
function listenToContentEvents(): ArrayObject
{
    $seen = new ArrayObject;
    Event::listen([EntrySaved::class, EntryPublished::class, EntryUnpublished::class], fn (object $event) => $seen->append($event));

    return $seen;
}

/** @param ArrayObject<int, object> $seen */
function eventNames(ArrayObject $seen): array
{
    return array_map(class_basename(...), $seen->getArrayCopy());
}

it('dispatches EntrySaved with the Entry, the Locale, whether the Entry was created and the actor', function () {
    $seen = listenToContentEvents();
    $ada = User::forceCreate(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'secret']);

    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour'], actor: $ada);
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Hello'], $entry, 'en');

    expect(eventNames($seen))->toBe(['EntrySaved', 'EntrySaved'])
        ->and($seen[0]->entry->id)->toBe($entry->id)
        ->and([$seen[0]->locale, $seen[0]->created, $seen[0]->actor?->getAuthIdentifier()])->toBe(['fr', true, $ada->id])
        ->and([$seen[1]->locale, $seen[1]->created, $seen[1]->actor])->toBe(['en', false, null])
        ->and($seen[1]->entry->title)->toBe('Bonjour');
});

it('dispatches EntryPublished when a Translation becomes visible, and EntryUnpublished when it returns to Draft', function () {
    $seen = listenToContentEvents();

    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'author' => 'Ada', 'status' => 'published']);
    resolve(SaveEntry::class)->handle(Article::class, ['summary' => 'Encore.'], $entry);
    resolve(SaveEntry::class)->handle(Article::class, ['status' => 'draft'], $entry);

    expect(eventNames($seen))->toBe(['EntrySaved', 'EntryPublished', 'EntrySaved', 'EntrySaved', 'EntryUnpublished'])
        ->and([$seen[1]->locale, $seen[1]->scheduled, $seen[1]->actor])->toBe(['fr', false, null])
        ->and($seen[4]->locale)->toBe('fr');
});

it('dispatches EntrySaved only for a publication date still to come', function () {
    $seen = listenToContentEvents();

    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Later', 'author' => 'Ada', 'status' => 'published', 'published_at' => now()->addDay()]);

    expect(eventNames($seen))->toBe(['EntrySaved']);
});

it('dispatches EntrySaved in \'*\' for an untranslatable type and a Shared-only call', function () {
    $seen = listenToContentEvents();

    $label = resolve(SaveEntry::class)->handle(Label::class, ['title' => 'Urgent', 'status' => 'published'], locale: 'en');
    $entry = resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour']);
    resolve(SaveEntry::class)->handle(Article::class, ['author' => 'Ada'], $entry, '*');

    expect(eventNames($seen))->toBe(['EntrySaved', 'EntryPublished', 'EntrySaved', 'EntrySaved'])
        ->and([$seen[0]->entry->id, $seen[0]->locale, $seen[1]->locale])->toBe([$label->id, '*', '*'])
        ->and([$seen[3]->locale, $seen[3]->created])->toBe(['*', false]);
});

it('dispatches nothing for a write that is rolled back', function () {
    $seen = listenToContentEvents();
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour']);
    $seen->exchangeArray([]);

    expect(fn () => resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour']))->toThrow(ValidationException::class);

    expect(fn () => DB::transaction(function () {
        resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Hello']);

        throw new RuntimeException('The caller gave up.');
    }))->toThrow(RuntimeException::class, 'The caller gave up.');

    expect(eventNames($seen))->toBe([])
        ->and(DB::table('kami_entries')->count())->toBe(1);
});

it('lets a listener\'s exception reach the caller, the write being committed', function () {
    Event::listen(EntrySaved::class, fn () => throw new RuntimeException('The listener failed.'));

    expect(fn () => resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour']))->toThrow(RuntimeException::class, 'The listener failed.')
        ->and(DB::table('kami_translations')->count())->toBe(1);
});
