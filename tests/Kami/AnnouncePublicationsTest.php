<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Actions\TrashEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Events\EntryPublished;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Routing\Internal\PageCache;
use Workbench\App\Content\Article;
use Workbench\App\Content\Label;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The test's own: parallel processes share the skeleton's public/ and storage/.
    $this->app->usePublicPath($this->public = temporaryPath());
    $this->app->useStoragePath($this->storage = temporaryPath());
    File::ensureDirectoryExists($this->public);
    Kami::routes();
});

afterEach(function () {
    File::deleteDirectory($this->public);
    File::deleteDirectory($this->storage);
});

function scheduledArticle(string $title, string $at, string $locale = 'fr'): Entry
{
    return resolve(SaveEntry::class)->handle(Article::class, ['title' => $title, 'author' => 'Ada', 'status' => 'published', 'published_at' => $at], locale: $locale);
}

/** @return list<string> The titles announced, each `scheduled`, with no actor. */
function announce(object $test, string $at): array
{
    $test->travelTo($at);
    $seen = new ArrayObject;
    Event::listen(EntryPublished::class, fn (EntryPublished $event) => $seen->append($event));

    $test->artisan('kami:announce-publications')->assertSuccessful();

    return array_map(function (EntryPublished $event): string {
        expect([$event->scheduled, $event->actor])->toBe([true, null]);

        return "{$event->locale}:{$event->entry->translation($event->locale)?->title}";
    }, $seen->getArrayCopy());
}

it('announces nothing on its first run, starting its window then', function () {
    $this->travelTo('2026-11-10 09:00:00');
    scheduledArticle('Passée', '2026-11-10 09:30:00');
    scheduledArticle('À venir', '2026-11-10 10:30:00');

    expect(announce($this, '2026-11-10 10:00:00'))->toBe([])
        ->and(announce($this, '2026-11-10 10:30:00'))->toBe(['fr:À venir']);
});

it('announces each Translation whose moment falls in (last run, now], once', function () {
    $this->travelTo('2026-11-10 08:59:00');
    scheduledArticle('À la seconde du run', '2026-11-10 09:00:00');
    announce($this, '2026-11-10 09:00:00');
    $this->travelTo('2026-11-10 09:00:00');
    scheduledArticle('Juste après', '2026-11-10 09:00:01');
    $bilingual = scheduledArticle('À la minute', '2026-11-10 09:01:00');
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'On the minute', 'status' => 'published', 'published_at' => '2026-11-10 09:01:00'], $bilingual, 'en');
    resolve(SaveEntry::class)->handle(Label::class, ['title' => 'Partout', 'status' => 'published', 'published_at' => '2026-11-10 09:01:00'], locale: 'fr');
    scheduledArticle('Une seconde trop tard', '2026-11-10 09:01:01');

    expect(announce($this, '2026-11-10 09:01:00'))->toEqualCanonicalizing(['fr:Juste après', 'fr:À la minute', 'en:On the minute', '*:Partout'])
        ->and(announce($this, '2026-11-10 09:01:00'))->toBe([])
        ->and(announce($this, '2026-11-10 09:02:00'))->toBe(['fr:Une seconde trop tard']);
});

it('leaves out what a save already announced, Drafts and the Trash', function () {
    announce($this, '2026-11-10 09:00:00');
    $this->travelTo('2026-11-10 09:00:00');
    $edited = scheduledArticle('Modifiée après son heure', '2026-11-10 09:30:00');
    $trashed = scheduledArticle('Corbeille', '2026-11-10 09:30:00');
    $draft = scheduledArticle('Brouillon', '2026-11-10 09:30:00');
    resolve(SaveEntry::class)->handle(Article::class, ['status' => 'draft'], $draft);
    resolve(TrashEntry::class)->handle($trashed);
    $this->travelTo('2026-11-10 09:45:00');
    resolve(SaveEntry::class)->handle(Article::class, ['summary' => 'Encore.'], $edited);
    // Published by its save, in the window: `SaveEntry` announced it.
    resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Antidatée', 'author' => 'Ada', 'status' => 'published', 'published_at' => '2026-11-10 09:15:00']);

    expect(announce($this, '2026-11-10 10:00:00'))->toBe([]);
});

it('lets a save after the moment announce a Translation the command has not, once', function () {
    $ada = User::forceCreate(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'secret']);
    announce($this, '2026-11-10 09:00:00');
    $this->travelTo('2026-11-10 09:00:00');
    $pending = scheduledArticle('En attente', '2026-11-10 09:30:00');
    $announced = scheduledArticle('Annoncée', '2026-11-10 09:10:00');
    announce($this, '2026-11-10 09:20:00');
    $seen = new ArrayObject;
    Event::listen(EntryPublished::class, fn (EntryPublished $event) => $seen->append($event));

    $this->travelTo('2026-11-10 09:45:00');
    resolve(SaveEntry::class)->handle(Article::class, ['summary' => 'Encore.'], $pending, actor: $ada);
    resolve(SaveEntry::class)->handle(Article::class, ['summary' => 'Encore.'], $pending);
    resolve(SaveEntry::class)->handle(Article::class, ['summary' => 'Encore.'], $announced);

    expect(array_map(fn (EntryPublished $event) => [$event->entry->id, $event->scheduled, $event->actor?->getAuthIdentifier()], $seen->getArrayCopy()))->toBe([[$pending->id, true, $ada->id]])
        ->and(announce($this, '2026-11-10 10:00:00'))->toBe([]);
});

it('leaves the announcement to the command\'s first run, which starts after the moment', function () {
    $this->travelTo('2026-11-10 09:00:00');
    $entry = scheduledArticle('Avant tout run', '2026-11-10 09:30:00');
    $seen = new ArrayObject;
    Event::listen(EntryPublished::class, fn (EntryPublished $event) => $seen->append($event));

    $this->travelTo('2026-11-10 09:45:00');
    resolve(SaveEntry::class)->handle(Article::class, ['summary' => 'Encore.'], $entry);

    expect($seen->getArrayCopy())->toBe([]);
});

it('reports a failing listener once, announcing the others and never replaying them', function () {
    Exceptions::fake();
    announce($this, '2026-11-10 09:00:00');
    $this->travelTo('2026-11-10 09:00:00');
    scheduledArticle('Refusée', '2026-11-10 09:10:00');
    scheduledArticle('Acceptée', '2026-11-10 09:20:00');
    Event::listen(EntryPublished::class, fn (EntryPublished $event) => $event->entry->title === 'Refusée' ? throw new RuntimeException('Webhook down.') : null);
    $seen = new ArrayObject;
    Event::listen(EntryPublished::class, fn (EntryPublished $event) => $seen->append($event->entry->title));
    $this->travelTo('2026-11-10 09:30:00');

    $this->artisan('kami:announce-publications')->expectsOutputToContain('1 announcement failed in a listener')->assertFailed();

    expect($seen->getArrayCopy())->toBe(['Acceptée'])
        ->and(announce($this, '2026-11-10 09:31:00'))->toBe([]);
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'Webhook down.');
});

it('marks the sitemap stale once something was announced, writing no row and keeping the Content version', function () {
    $this->travelTo('2026-11-10 09:00:00');
    scheduledArticle('Première', '2026-11-10 09:30:00');
    scheduledArticle('Seconde', '2026-11-10 10:30:00');
    // Past the page cache manifest's next change, which the sitemap reads on its own.
    $this->travelTo('2026-11-10 09:40:00');
    $this->artisan('kami:sitemap')->expectsOutputToContain('Wrote the sitemap: 1 URLs.')->assertSuccessful();
    announce($this, '2026-11-10 09:40:00');
    $this->travelTo('2026-11-10 10:40:00');
    $this->artisan('kami:sitemap')->expectsOutputToContain('The sitemap is up to date.')->assertSuccessful();
    [$version, $runs] = [PageCache::version(), DB::table('kami_task_runs')->count()];

    expect(announce($this, '2026-11-10 10:40:00'))->toBe(['fr:Seconde'])
        ->and([PageCache::version(), DB::table('kami_task_runs')->count()])->toBe([$version, $runs]);
    // A build in the second of a write rebuilds once more, to be safe: the steps stay a minute apart.
    $this->travelTo('2026-11-10 10:41:00');
    $this->artisan('kami:sitemap')->expectsOutputToContain('Wrote the sitemap: 2 URLs.')->assertSuccessful();

    announce($this, '2026-11-10 10:50:00');
    $this->artisan('kami:sitemap')->expectsOutputToContain('The sitemap is up to date.')->assertSuccessful();
});

it('runs every minute from Kami::schedule()', function () {
    Kami::schedule();

    $event = collect(resolve(Schedule::class)->events())->first(fn (ScheduledEvent $event) => str_ends_with((string) $event->command, 'kami:announce-publications'));

    expect($event?->expression)->toBe('* * * * *');
});
