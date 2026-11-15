<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Psyao\Kami\Content\Actions\DeleteEntry;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Actions\TrashEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Redirects\Actions\DeleteRedirect;
use Psyao\Kami\Redirects\Actions\SaveRedirect;
use Psyao\Kami\Redirects\Events\RedirectDeleted;
use Psyao\Kami\Redirects\Events\RedirectSaved;
use Psyao\Kami\Redirects\Redirect;
use Psyao\Kami\Routing\Internal\PageCache;
use Workbench\App\Content\Page;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Kami::routes();
});

function saveRedirect(string $source, int $status = 301, Entry | string | null $target = '/elsewhere', ?string $locale = null): Redirect
{
    return resolve(SaveRedirect::class)->handle($source, $status, $target, $locale);
}

/** @param array<string, mixed> $values */
function redirectedPage(string $title = 'À propos', array $values = []): Entry
{
    return resolve(SaveEntry::class)->handle(Page::class, ['title' => $title, 'status' => 'published', ...$values], locale: 'fr');
}

describe('answering', function (): void {
    it('answers 301 and 302 with a free target as stored, and 410', function (): void {
        saveRedirect('/blog', 301, '/articles');
        saveRedirect('/old-feed', 302, 'https://example.com/feed?format=rss');
        saveRedirect('/gone', 410, null);

        $this->get('/blog')->assertMovedPermanently()->assertHeader('Location', '/articles');
        $this->get('/old-feed')->assertFound()->assertHeader('Location', 'https://example.com/feed?format=rss');
        $this->get('/gone')->assertGone();
    });

    it('sends a Redirect to an Entry to its current URL, a 302 as a 302', function (): void {
        $page = redirectedPage();
        saveRedirect('/about', 302, $page);

        resolve(SaveEntry::class)->handle(Page::class, ['slug' => 'qui-sommes-nous'], $page, 'fr');

        $this->get('/about')->assertFound()->assertRedirect('http://localhost/qui-sommes-nous');
    });

    it('answers a Redirect in its own Locale only', function (): void {
        saveRedirect('/blog', target: '/en/articles', locale: 'en');

        $this->get('/en/blog')->assertMovedPermanently()->assertHeader('Location', '/en/articles');
        $this->get('/blog')->assertNotFound();
    });

    it('matches a query string, then a pair, then a key alone, then the path, ignoring other parameters', function (string $request, string $location): void {
        saveRedirect('/?p=123', target: '/post-123');
        saveRedirect('/?p', target: '/any-post');
        saveRedirect('/?a=1&b=2', target: '/both');
        saveRedirect('/?attachment_id', 410, null);
        saveRedirect('/archive?year=2020', target: '/2020');
        saveRedirect('/archive', target: '/archives');

        $response = $this->get($request);

        $location === '410' ? $response->assertGone() : $response->assertMovedPermanently()->assertHeader('Location', $location);
    })->with([
        'a pair' => ['/?p=123', '/post-123'],
        'a pair among unknown parameters' => ['/?utm_source=x&p=123', '/post-123'],
        'a pair in another case and encoding' => ['/?P=%31%323', '/post-123'],
        'a key alone, any value' => ['/?p=456', '/any-post'],
        'a key alone, no value' => ['/?p', '/any-post'],
        'the whole query string, in any order' => ['/?b=2&a=1', '/both'],
        'a key alone to 410' => ['/?attachment_id=12', '410'],
        'a path with its query string' => ['/archive?year=2020', '/2020'],
        'a path with unknown parameters' => ['/archive?year=2021', '/archives'],
    ]);

    it('renders a live Path whatever its query string matches no Redirect', function (): void {
        redirectedPage();
        saveRedirect('/a-propos?lang=en', target: '/en/about');

        $this->get('/a-propos?utm_source=newsletter')->assertOk();
        $this->get('/a-propos?lang=en')->assertMovedPermanently()->assertHeader('Location', '/en/about');
    });

    it('answers 404 to a Redirect whose Entry is not visible, and to an encoded `?` in the path', function (): void {
        saveRedirect('/about', 302, redirectedPage('Brouillon', ['status' => 'draft']));
        saveRedirect('/blog?p=1', target: '/articles');

        $this->get('/about')->assertNotFound();
        $this->get('/blog%3Fp=1')->assertNotFound();
        $this->get('/blog?p=1')->assertMovedPermanently();
    });

    it('reads 20 query pairs at most, those that sort first', function (): void {
        saveRedirect('/?a01=1', target: '/first');
        saveRedirect('/?zz=1', target: '/last');
        $others = implode('&', array_map(fn (int $n): string => sprintf('b%02d=1', $n), range(1, 2000)));

        $this->get("/?zz=1&{$others}")->assertNotFound();
        $this->get("/?zz=1&a01=1&{$others}")->assertMovedPermanently()->assertHeader('Location', '/first');
    });

    it('keeps a 302 temporary when its Entry is deleted for a replacement', function (): void {
        $page = redirectedPage();
        $replacement = redirectedPage('Équipe');
        saveRedirect('/about', 302, $page);
        resolve(TrashEntry::class)->handle($page);

        resolve(DeleteEntry::class)->handle(Entry::query()->withTrashed()->findOrFail($page->id), $replacement);

        $this->get('/about')->assertFound()->assertRedirect('http://localhost/equipe');
        $this->get('/a-propos')->assertMovedPermanently()->assertRedirect('http://localhost/equipe');
    });

    it('resolves a query string in one query, exact', function (): void {
        saveRedirect('/?p=123', target: '/post-123');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/?utm_source=x&p=123&a=1')->assertMovedPermanently();

        expect(DB::getQueryLog())->toHaveCount(1);
    });
});

describe('saving', function (): void {
    it('stores the source in canonical form, and updates it when a source is saved again', function (): void {
        Event::fake([RedirectSaved::class]);
        $actor = auditUser();
        $first = resolve(SaveRedirect::class)->handle('/Blog/?B=2&a=caf%C3%A9', 301, '/articles', actor: $actor);

        $again = saveRedirect('blog?a=café&b=2', 410, null);

        expect($first->path)->toBe('blog?a=caf%C3%A9&b=2')
            ->and($again->id)->toBe($first->id)
            ->and(Redirect::query()->sole()->only(['path', 'status', 'entry_id', 'target']))->toBe(['path' => 'blog?a=caf%C3%A9&b=2', 'status' => 410, 'entry_id' => null, 'target' => null])
            ->and(array_map(fn (array $event): array => [$event['event'], $event['subject_type'], $event['user_id'], $event['context']], auditEvents()))->toBe([
                ['kami.redirects.created', 'redirect', $actor->id, ['locale' => 'fr', 'source' => 'blog?a=caf%C3%A9&b=2', 'status' => 301]],
                ['kami.redirects.updated', 'redirect', null, ['locale' => 'fr', 'source' => 'blog?a=caf%C3%A9&b=2', 'status' => 410]],
            ]);
        Event::assertDispatched(RedirectSaved::class, fn (RedirectSaved $event): bool => $event->redirect->is($first) && $event->created && $event->actor === $actor);
        Event::assertDispatched(RedirectSaved::class, fn (RedirectSaved $event): bool => ! $event->created && $event->actor === null);
    });

    it('takes over a Path Kami left redirecting when an Entry moved', function (): void {
        $page = redirectedPage();
        resolve(SaveEntry::class)->handle(Page::class, ['slug' => 'qui-sommes-nous'], $page, 'fr');

        saveRedirect('/a-propos', 410, null);

        $this->get('/a-propos')->assertGone();
    });

    it('refuses an invalid Redirect, changing nothing', function (Closure $save, string $key): void {
        $page = redirectedPage();
        saveRedirect('/blog', target: '/articles');

        expect(fn () => $save($page))->toThrow(function (ValidationException $exception) use ($key): void {
            expect(array_keys($exception->errors()))->toBe([$key]);
        })->and(Redirect::query()->pluck('path')->unique()->all())->toBe(['blog']);
    })->with([
        'a source with a space' => [fn () => saveRedirect('/a b'), 'source'],
        'a source over 20 query pairs' => [fn () => saveRedirect('/?' . implode('&', array_map(fn (int $n): string => "k{$n}=1", range(1, 21)))), 'source'],
        'a source over 500 characters' => [fn () => saveRedirect('/' . str_repeat('a', 501)), 'source'],
        'a reserved source' => [fn () => saveRedirect('/media/photo.jpg'), 'source'],
        'an Archive' => [fn () => saveRedirect('/articles?page=2'), 'source'],
        'a Feed' => [fn () => saveRedirect('/feed'), 'source'],
        'a Locale segment' => [fn () => saveRedirect('/en/blog'), 'source'],
        'a live Path' => [fn () => saveRedirect('/a-propos'), 'source'],
        'a status' => [fn () => saveRedirect('/x', 307), 'status'],
        'a 301 with no target' => [fn () => saveRedirect('/x', 301, null), 'target'],
        'a 410 with a target' => [fn () => saveRedirect('/x', 410, '/y'), 'target'],
        'a protocol-relative address' => [fn () => saveRedirect('/x', target: '//evil.example'), 'target'],
        'a backslash address' => [fn () => saveRedirect('/x', target: '/\evil.example'), 'target'],
        'a script address' => [fn () => saveRedirect('/x', target: 'javascript:alert(1)'), 'target'],
        'a fragment' => [fn () => saveRedirect('/x', target: '#top'), 'target'],
        'an Entry in the Trash' => [function (Entry $page) {
            resolve(TrashEntry::class)->handle($page);

            return saveRedirect('/x', target: $page);
        }, 'target'],
        'a target that redirects' => [fn () => saveRedirect('/x', target: '/blog?utm_source=y'), 'target'],
        'a target that is its own source' => [fn () => saveRedirect('/x', target: '/x?y=1'), 'target'],
        'a target that redirects in its Locale' => [fn () => saveRedirect('/blog', target: '/articles', locale: 'en') && saveRedirect('/x', target: '/en/blog', locale: 'en'), 'target'],
    ]);

    it('names the Entry whose current Path a source is', function (): void {
        redirectedPage();

        expect(fn () => saveRedirect('/a-propos'))->toThrow(ValidationException::class, 'À propos');
    });

    it('lets a target leave the Locale or the Site, unchecked', function (): void {
        saveRedirect('/blog', target: '/articles');

        expect(saveRedirect('/x', target: '/en/blog')->target)->toBe('/en/blog')
            ->and(saveRedirect('/y', target: 'https://example.com/blog')->target)->toBe('https://example.com/blog');
    });
});

it('deletes a Redirect, with an Audit event and an event', function (): void {
    Event::fake([RedirectDeleted::class]);
    $redirect = saveRedirect('/blog');

    resolve(DeleteRedirect::class)->handle($redirect);
    resolve(DeleteRedirect::class)->handle($redirect);

    expect(Redirect::query()->count())->toBe(0)
        ->and(array_column(auditEvents(), 'event'))->toBe(['kami.redirects.created', 'kami.redirects.deleted']);
    Event::assertDispatchedTimes(RedirectDeleted::class, 1);
    Event::assertDispatched(RedirectDeleted::class, fn (RedirectDeleted $event): bool => [$event->redirectId, $event->site, $event->locale, $event->source] === [$redirect->id, 'default', 'fr', 'blog']);
});

it('makes a new Content version on every Redirect write', function (): void {
    $versions = [PageCache::version()];
    $redirect = saveRedirect('/blog');
    $versions[] = PageCache::version();
    saveRedirect('/blog', 410, null);
    $versions[] = PageCache::version();
    resolve(DeleteRedirect::class)->handle($redirect);
    $versions[] = PageCache::version();

    expect(array_unique($versions))->toHaveCount(4);
});

it('reads the Redirects, Kami’s dated, and never a current Path', function (): void {
    $this->freezeSecond();
    $page = redirectedPage();
    resolve(SaveEntry::class)->handle(Page::class, ['slug' => 'qui-sommes-nous'], $page, 'fr');
    saveRedirect('/blog', 302, $page);

    expect(Redirect::query()->with('entry')->orderBy('path')->get()->map(fn (Redirect $redirect): array => [$redirect->path, $redirect->status, $redirect->entry?->id, $redirect->updated_at?->toDateTimeString()])->all())->toBe([
        ['a-propos', 301, $page->id, now()->toDateTimeString()],
        ['blog', 302, $page->id, now()->toDateTimeString()],
    ]);
});
