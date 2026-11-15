<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Psyao\Kami\Admin\Internal\EntryResource\ListEntries;
use Psyao\Kami\Content\Actions\DeleteEntry;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Actions\TrashEntry;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Menus\Actions\DeleteMenuItem;
use Psyao\Kami\Menus\Actions\MoveMenuItem;
use Psyao\Kami\Menus\Actions\SaveMenuItem;
use Psyao\Kami\Menus\Events\MenuItemDeleted;
use Psyao\Kami\Menus\Events\MenuItemMoved;
use Psyao\Kami\Menus\Events\MenuItemSaved;
use Psyao\Kami\Menus\MenuItem;
use Psyao\Kami\Menus\MenuLink;
use Psyao\Kami\Routing\Internal\PageCache;
use Psyao\Kami\Sites\Internal\Site;
use Workbench\App\Content\Article;
use Workbench\App\Content\Footer;
use Workbench\App\Content\Home;
use Workbench\App\Content\Page;
use Workbench\App\Content\Project;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['kami.menus' => ['primary' => ['depth' => 2], 'footer' => [], 'deep' => ['depth' => 3]]]);
});

/** @param array<string, mixed> $values */
function menuItem(array $values, string $menu = 'primary'): MenuItem
{
    return resolve(SaveMenuItem::class)->handle($menu, $values);
}

/** @param array<string, mixed> $values */
function menuPage(string $title, array $values = [], string $locale = 'fr', ?Entry $entry = null): Entry
{
    return resolve(SaveEntry::class)->handle(Page::class, ['title' => $title, 'status' => 'published', ...$values], $entry, $locale);
}

/** @return list<string> The Menu's items as `label@parent#position`, in tree order. */
function menuShape(string $menu = 'primary'): array
{
    return MenuItem::query()->where('menu', $menu)->orderBy('parent_id')->orderBy('position')->get()
        ->map(fn (MenuItem $item): string => ($item->label['fr'] ?? '?') . '@' . ($item->parent_id === null ? 'root' : (MenuItem::query()->find($item->parent_id)->label['fr'] ?? '?')) . '#' . $item->position)
        ->sort()->values()->all();
}

it('saves an Entry, an Archive and an address item, each last among its siblings, with one Audit event and event each', function (): void {
    Event::fake([MenuItemSaved::class]);
    $about = menuPage('À propos');
    $actor = auditUser();

    $page = resolve(SaveMenuItem::class)->handle('primary', ['entry' => $about], actor: $actor);
    $archive = menuItem(['archive' => 'article', 'label' => ['fr' => 'Articles', 'en' => 'Articles']]);
    $address = menuItem(['url' => 'https://example.com', 'label' => ['fr' => 'Ailleurs'], 'new_tab' => true]);

    expect([$page->position, $archive->position, $address->position])->toBe([1, 2, 3])
        ->and($page->only(['site', 'menu', 'parent_id', 'entry_id', 'archive', 'url', 'label', 'new_tab']))->toBe(['site' => 'default', 'menu' => 'primary', 'parent_id' => null, 'entry_id' => $about->id, 'archive' => null, 'url' => null, 'label' => null, 'new_tab' => false])
        ->and($address->fresh()?->url)->toBe(['fr' => 'https://example.com'])
        ->and($address->fresh()?->new_tab)->toBeTrue()
        ->and(array_values(array_map(fn (array $event): array => [$event['event'], $event['subject_type'], $event['user_id'], $event['context']], array_filter(auditEvents(), fn (array $event): bool => $event['subject_type'] === 'menu-item'))))->toBe([
            ['kami.menu-items.created', 'menu-item', $actor->id, ['menu' => 'primary']],
            ['kami.menu-items.created', 'menu-item', null, ['menu' => 'primary']],
            ['kami.menu-items.created', 'menu-item', null, ['menu' => 'primary']],
        ]);
    Event::assertDispatched(MenuItemSaved::class, fn (MenuItemSaved $event): bool => $event->item->is($page) && $event->created && $event->actor === $actor);
    Event::assertDispatchedTimes(MenuItemSaved::class, 3);
});

it('changes a stored item: a label kept when left out, a Locale cleared, a target switching the others off', function (): void {
    $item = menuItem(['url' => ['fr' => '/contact', 'en' => '/en/contact'], 'label' => ['fr' => 'Contact', 'en' => 'Contact']]);
    Event::fake([MenuItemSaved::class]);

    resolve(SaveMenuItem::class)->handle('primary', ['label' => ['en' => 'Reach us'], 'url' => ['en' => '']], $item);

    // Compared as maps: MySQL's json keeps no key order.
    expect($item->fresh()?->only(['url', 'label']))->toEqual(['url' => ['fr' => '/contact'], 'label' => ['fr' => 'Contact', 'en' => 'Reach us']]);

    resolve(SaveMenuItem::class)->handle('primary', ['archive' => 'article'], $item);

    expect($item->fresh()?->only(['url', 'archive', 'label', 'position']))->toEqual(['url' => null, 'archive' => 'article', 'label' => ['fr' => 'Contact', 'en' => 'Reach us'], 'position' => 1])
        ->and(array_column(auditEvents(), 'event'))->toBe(['kami.menu-items.created', 'kami.menu-items.updated', 'kami.menu-items.updated']);
    Event::assertDispatched(MenuItemSaved::class, fn (MenuItemSaved $event): bool => ! $event->created);
});

it('allows only the listed addresses, in every Locale', function (string $address, bool $allowed): void {
    $save = fn () => menuItem(['url' => ['fr' => '/ok', 'en' => $address], 'label' => ['fr' => 'Lien']]);

    $allowed ? expect($save()->url['en'])->toBe($address) : expect($save)->toThrow(ValidationException::class, 'An address starts with https://, http://, /, #, mailto: or tel:.');
})->with([
    ['https://example.com/a?b=c#d', true],
    ['http://example.com', true],
    ['/photos/mariage', true],
    ['#contenu', true],
    ['mailto:ada@example.com', true],
    ['tel:+41790000000', true],
    ['//evil.example', false],
    ['javascript:alert(1)', false],
    ['JavaScript:alert(1)', false],
    ['data:text/html,x', false],
    ['https://', false],
    ['photos', false],
    ['/a b', false],
    ['/\\evil.example', false],
    ['/a\\b', false],
]);

it('refuses an address with no URL in the default Locale', function (): void {
    expect(fn () => menuItem(['url' => ['en' => '/en/contact'], 'label' => ['en' => 'Contact']]))
        ->toThrow(ValidationException::class, 'An address needs a URL in FR, the default language.');
});

it('refuses no target, several targets, and a target that cannot be linked', function (array $values, string $field): void {
    try {
        menuItem($values);
        $this->fail('Saved');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe([$field]);
    }

    expect(MenuItem::query()->count())->toBe(0);
})->with([
    'no target' => [['label' => ['fr' => 'Rien']], 'target'],
    'several' => [fn () => ['archive' => 'article', 'url' => '/ailleurs'], 'target'],
    'a missing Entry' => [['entry' => '01JAAAAAAAAAAAAAAAAAAAAAAA'], 'entry'],
    'an Entry in the Trash' => [fn () => ['entry' => tap(menuPage('Partie'), fn (Entry $entry) => resolve(TrashEntry::class)->handle($entry))->id], 'entry'],
    'an Entry with no address' => [fn () => ['entry' => Footer::entry()?->id ?? resolve(SaveEntry::class)->handle(Footer::class, ['tagline' => 'x'], locale: 'fr')->id], 'entry'],
    'a type with no Archive' => [['archive' => 'page'], 'archive'],
    'an undeclared type' => [['archive' => 'nope'], 'archive'],
]);

it('links a Draft, hidden until it is published', function (): void {
    $draft = menuPage('Bientôt', ['status' => 'draft']);

    expect(menuItem(['entry' => $draft->id])->entry_id)->toBe($draft->id)
        ->and(Kami::menu('primary'))->toBe([]);

    menuPage('Bientôt', ['status' => 'published'], entry: $draft);

    expect(Kami::menu('primary')[0]->label)->toBe('Bientôt');
});

it('leaves the caller\'s item as it was when a save is refused, and gives it the saved values otherwise', function (): void {
    $item = menuItem(['url' => '/a', 'label' => ['fr' => 'A']]);

    expect(fn () => resolve(SaveMenuItem::class)->handle('primary', ['url' => 'javascript:alert(1)', 'new_tab' => true], $item))->toThrow(ValidationException::class);

    expect($item->url)->toBe(['fr' => '/a'])
        ->and($item->new_tab)->toBeFalse()
        ->and($item->isDirty())->toBeFalse();

    $saved = resolve(SaveMenuItem::class)->handle('primary', ['label' => ['fr' => 'B']], $item);

    expect($saved)->toBe($item)
        ->and($item->label)->toBe(['fr' => 'B'])
        ->and($item->isDirty())->toBeFalse();
});

it('keeps an item left too deep by a lowered depth editable, without moving it', function (): void {
    $root = menuItem(['url' => '/a', 'label' => ['fr' => 'A']]);
    $child = menuItem(['url' => '/b', 'label' => ['fr' => 'B'], 'parent' => $root->id]);
    config(['kami.menus.primary.depth' => 1]);

    expect(resolve(SaveMenuItem::class)->handle('primary', ['label' => ['fr' => 'B2']], $child)->label)->toBe(['fr' => 'B2'])
        ->and(fn () => menuItem(['url' => '/c', 'label' => ['fr' => 'C'], 'parent' => $root->id]))->toThrow(ValidationException::class, 'This Menu has a single level.');
});

it('throws for an undeclared Menu, an unknown Locale, an unknown key, and an item of another Menu', function (): void {
    expect(fn () => menuItem(['url' => '/a', 'label' => ['fr' => 'A']], 'nope'))->toThrow(InvalidArgumentException::class, 'No Menu [nope] is declared under kami.menus.')
        ->and(fn () => menuItem(['url' => '/a', 'label' => ['de' => 'A']]))->toThrow(InvalidArgumentException::class, 'No Locale [de] is declared in Site [default].')
        ->and(fn () => menuItem(['url' => '/a', 'colour' => 'red']))->toThrow(ValidationException::class);

    config(['kami.menus.' . str_repeat('m', 41) => []]);

    expect(fn () => menuItem(['url' => '/a', 'label' => ['fr' => 'A']], str_repeat('m', 41)))->toThrow(InvalidArgumentException::class, 'is over 40 characters');

    $footer = menuItem(['url' => '/a', 'label' => ['fr' => 'A']], 'footer');

    expect(fn () => resolve(SaveMenuItem::class)->handle('primary', ['label' => ['fr' => 'B']], $footer))->toThrow(InvalidArgumentException::class, 'is not in the Menu [primary]');
});

it('refuses a depth outside 1 to 3', function (mixed $depth): void {
    config(['kami.menus.primary.depth' => $depth]);

    expect(fn () => menuItem(['url' => '/a', 'label' => ['fr' => 'A']]))->toThrow(InvalidArgumentException::class, 'declares a depth of 1 to 3');
})->with([0, 4, '2']);

it('nests within the declared depth, and refuses a Parent from another Menu, the item itself or its subtree', function (): void {
    $root = menuItem(['url' => '/a', 'label' => ['fr' => 'A']]);
    $child = menuItem(['url' => '/b', 'label' => ['fr' => 'B'], 'parent' => $root->id]);
    $footer = menuItem(['url' => '/f', 'label' => ['fr' => 'F']], 'footer');
    $flat = menuItem(['url' => '/g', 'label' => ['fr' => 'G']], 'footer');

    expect(fn () => menuItem(['url' => '/c', 'label' => ['fr' => 'C'], 'parent' => $child->id]))->toThrow(ValidationException::class, 'This Menu nests 2 levels at most.')
        ->and(fn () => menuItem(['url' => '/c', 'label' => ['fr' => 'C'], 'parent' => $footer->id]))->toThrow(ValidationException::class, 'The parent is not an item of this Menu.')
        ->and(fn () => resolve(SaveMenuItem::class)->handle('footer', ['parent' => $footer->id], $flat))->toThrow(ValidationException::class, 'This Menu has a single level.')
        ->and(fn () => resolve(SaveMenuItem::class)->handle('primary', ['parent' => $root->id], $root))->toThrow(ValidationException::class, 'An item cannot sit under itself or one of its children.');

    // Moving a root with children under another root would make three levels.
    $other = menuItem(['url' => '/d', 'label' => ['fr' => 'D']]);

    expect(fn () => resolve(SaveMenuItem::class)->handle('primary', ['parent' => $other->id], $root))->toThrow(ValidationException::class, 'This Menu nests 2 levels at most.');

    $deep = menuItem(['url' => '/x', 'label' => ['fr' => 'X']], 'deep');
    $under = menuItem(['url' => '/y', 'label' => ['fr' => 'Y'], 'parent' => $deep->id], 'deep');

    expect(fn () => resolve(SaveMenuItem::class)->handle('deep', ['parent' => $under->id], $deep))->toThrow(ValidationException::class, 'An item cannot sit under itself or one of its children.');
});

it('puts an item changing Parent last among its new siblings', function (): void {
    $a = menuItem(['url' => '/a', 'label' => ['fr' => 'A']]);
    menuItem(['url' => '/a1', 'label' => ['fr' => 'A1'], 'parent' => $a->id]);
    $b = menuItem(['url' => '/b', 'label' => ['fr' => 'B']]);

    resolve(SaveMenuItem::class)->handle('primary', ['parent' => $a->id], $b);

    expect(menuShape())->toBe(['A1@A#1', 'A@root#1', 'B@A#2']);
});

it('moves an item among its siblings, out-of-range ranks brought back, with one Audit event and event; a still move records nothing', function (): void {
    $a = menuItem(['url' => '/a', 'label' => ['fr' => 'A']]);
    $b = menuItem(['url' => '/b', 'label' => ['fr' => 'B']]);
    $c = menuItem(['url' => '/c', 'label' => ['fr' => 'C']]);
    Event::fake([MenuItemMoved::class]);

    resolve(MoveMenuItem::class)->handle($c, 1);

    expect(menuShape())->toBe(['A@root#2', 'B@root#3', 'C@root#1']);

    resolve(MoveMenuItem::class)->handle($c, 99);
    resolve(MoveMenuItem::class)->handle($b, -5);

    expect(menuShape())->toBe(['A@root#2', 'B@root#1', 'C@root#3']);

    resolve(MoveMenuItem::class)->handle($c->fresh(), 3);

    // An instance read before its Parent changed elsewhere moves among its new siblings.
    $stale = $b->fresh();
    resolve(SaveMenuItem::class)->handle('primary', ['parent' => $a->id], $b->fresh());
    $c1 = menuItem(['url' => '/c1', 'label' => ['fr' => 'C1'], 'parent' => $a->id]);
    resolve(MoveMenuItem::class)->handle($c1, 1);
    resolve(MoveMenuItem::class)->handle($stale, 1);

    // B left a gap at the root, which orders the same.
    expect(menuShape())->toBe(['A@root#2', 'B@A#1', 'C1@A#2', 'C@root#3']);

    expect(array_slice(array_column(auditEvents(), 'event'), 3, 3))->toBe(['kami.menu-items.moved', 'kami.menu-items.moved', 'kami.menu-items.moved'])
        ->and(auditEvents()[3]['context'])->toBe(['menu' => 'primary', 'position' => 1]);
    Event::assertDispatchedTimes(MenuItemMoved::class, 5);
});

it('deletes an item, its children moving up one level in its place and order, with one Audit event and event', function (): void {
    $a = menuItem(['url' => '/a', 'label' => ['fr' => 'A']]);
    $b = menuItem(['url' => '/b', 'label' => ['fr' => 'B']]);
    menuItem(['url' => '/b1', 'label' => ['fr' => 'B1'], 'parent' => $b->id]);
    menuItem(['url' => '/b2', 'label' => ['fr' => 'B2'], 'parent' => $b->id]);
    menuItem(['url' => '/c', 'label' => ['fr' => 'C']]);
    Event::fake([MenuItemDeleted::class]);
    $actor = auditUser();

    resolve(DeleteMenuItem::class)->handle($b, $actor);

    expect(menuShape())->toBe(['A@root#1', 'B1@root#2', 'B2@root#3', 'C@root#4'])
        ->and(end(...[auditEvents()])['event'])->toBe('kami.menu-items.deleted');
    Event::assertDispatched(MenuItemDeleted::class, fn (MenuItemDeleted $event): bool => $event->itemId === $b->id && $event->menu === 'primary' && $event->site === 'default' && $event->actor === $actor);

    // Gone already: nothing happens twice.
    resolve(DeleteMenuItem::class)->handle($b);

    Event::assertDispatchedTimes(MenuItemDeleted::class, 1);
    expect($a->fresh()?->position)->toBe(1);
});

it('makes a new Content version on every Menu item write', function (): void {
    $versions = [];
    $version = fn (): ?string => PageCache::version();
    $versions[] = $version();
    $item = menuItem(['url' => '/a', 'label' => ['fr' => 'A']]);
    $versions[] = $version();
    menuItem(['url' => '/b', 'label' => ['fr' => 'B']]);
    resolve(MoveMenuItem::class)->handle($item, 2);
    $versions[] = $version();
    resolve(DeleteMenuItem::class)->handle($item);
    $versions[] = $version();

    expect(array_unique($versions))->toHaveCount(4);
});

describe('reading', function (): void {
    beforeEach(function (): void {
        Kami::routes();
    });

    it('renders the visible tree in each Locale, labels falling back to the target\'s title, never to another Locale\'s label', function (): void {
        $about = menuPage('À propos');
        menuPage('About', locale: 'en', entry: $about);
        $team = menuPage('Équipe', ['parent' => $about->id]);
        $parent = menuItem(['entry' => $about->id, 'label' => ['fr' => 'Qui sommes-nous']]);
        menuItem(['entry' => $team->id, 'parent' => $parent->id]);
        menuItem(['archive' => 'article']);
        menuItem(['url' => 'https://example.com', 'label' => ['fr' => 'Ailleurs', 'en' => 'Elsewhere'], 'new_tab' => true]);
        menuItem(['url' => '/fr-seulement', 'label' => ['fr' => 'En français']]);

        $this->get('/a-propos')->assertOk()->assertSeeInOrder([
            '<nav class="kami-menu" aria-label="Menu principal">',
            '<ul class="kami-menu-list">',
            '<li class="kami-menu-item">',
            '<a href="http://localhost/a-propos" aria-current="page">Qui sommes-nous</a>',
            '<ul class="kami-menu-list">',
            '<a href="http://localhost/a-propos/equipe">Équipe</a>',
            '</ul>',
            '<a href="http://localhost/articles">Articles</a>',
            '<a href="https://example.com" target="_blank" rel="noopener">Ailleurs</a>',
            '<a href="/fr-seulement">En français</a>',
            '</ul>',
            '</nav>',
        ], escape: false);

        // In English: the Page's English title, no French label, the Team Page hidden with no English Translation, the French-only address hidden.
        $this->get('/en/about')->assertOk()
            ->assertSee('<nav class="kami-menu" aria-label="Main menu">', escape: false)
            ->assertSee('<a href="http://localhost/en/about" aria-current="page">About</a>', escape: false)
            ->assertSee('<a href="http://localhost/en/articles">Articles</a>', escape: false)
            ->assertSee('<a href="https://example.com" target="_blank" rel="noopener">Elsewhere</a>', escape: false)
            ->assertDontSee('Qui sommes-nous')
            ->assertDontSee('Équipe')
            ->assertDontSee('En français');
    });

    it('hides an item with everything under it where its target is not visible, and an Archive item whose type has no Archive', function (): void {
        $draft = menuPage('Brouillon', ['status' => 'draft']);
        $parent = menuPage('Parent');
        $child = menuPage('Enfant', ['parent' => $parent->id]);
        $hidden = menuItem(['entry' => $draft->id]);
        menuItem(['url' => '/sous-brouillon', 'label' => ['fr' => 'Sous le brouillon'], 'parent' => $hidden->id]);
        menuItem(['entry' => $child->id]);
        menuItem(['archive' => 'article']);
        menuPage('Parent', ['status' => 'draft'], entry: $parent);

        expect(Kami::menu('primary'))->toHaveCount(1);

        // A change Kami cannot see: the Menu read in this request is read again.
        config(['kami.types' => array_values(array_diff(config('kami.types'), [Article::class, Project::class]))]);
        Kami::flushCache();

        expect(Kami::menu('primary'))->toBe([]);
    });

    it('falls back to the default Locale\'s address, and leaves out items deeper than the declared depth', function (): void {
        $root = menuItem(['url' => '/contact', 'label' => ['fr' => 'Contact', 'en' => 'Contact']], 'deep');
        $child = menuItem(['url' => '/a', 'label' => ['fr' => 'A', 'en' => 'A'], 'parent' => $root->id], 'deep');
        menuItem(['url' => '/b', 'label' => ['fr' => 'B', 'en' => 'B'], 'parent' => $child->id], 'deep');
        Site::useLocale('en');

        expect(Kami::menu('deep')[0]->url)->toBe('/contact')
            ->and(Kami::menu('deep')[0]->children[0]->children)->toHaveCount(1);

        config(['kami.menus.deep.depth' => 1]);
        Kami::flushCache();

        expect(Kami::menu('deep')[0]->children)->toBe([]);
    });

    it('marks the Trail by path: ancestors, an Archive\'s Entries, a path under an item that is not its Parent, never Home by prefix', function (string $path, array $current, array $trail): void {
        resolve(SaveEntry::class)->handle(Home::class, ['title' => 'Accueil', 'status' => 'published'], locale: 'fr');
        $about = menuPage('À propos');
        $team = menuPage('Équipe', ['parent' => $about->id]);
        resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Bonjour', 'status' => 'published', 'author' => 'Ada'], locale: 'fr');
        menuPage('Photos');
        menuItem(['entry' => Home::entry()?->id, 'label' => ['fr' => 'Accueil']]);
        $aboutItem = menuItem(['entry' => $about->id]);
        menuItem(['entry' => $team->id, 'parent' => $aboutItem->id]);
        menuItem(['archive' => 'article']);
        menuItem(['url' => 'http://localhost/a-propos/equipe?ref=menu', 'label' => ['fr' => 'Équipe, encore']]);
        menuItem(['url' => '/a', 'label' => ['fr' => 'A, page sœur de À propos']]);
        menuItem(['url' => 'https://ailleurs.example/a-propos', 'label' => ['fr' => 'Ailleurs']]);
        menuItem(['url' => '#contenu', 'label' => ['fr' => 'Contenu']]);

        $this->get($path)->assertOk();
        $flat = fn (array $links): array => collect($links)->flatMap(fn (MenuLink $link): array => [$link, ...$link->children])->all();
        $links = $flat(Kami::menu('primary'));

        expect(array_values(array_map(fn (MenuLink $link): string => $link->label, array_filter($links, fn (MenuLink $link): bool => $link->isCurrent))))->toBe($current)
            ->and(array_values(array_map(fn (MenuLink $link): string => $link->label, array_filter($links, fn (MenuLink $link): bool => $link->inTrail))))->toBe($trail);
    })->with([
        'home' => ['/', ['Accueil'], []],
        'a child, its ancestor in the Trail' => ['/a-propos/equipe', ['Équipe', 'Équipe, encore'], ['À propos']],
        'an Entry of the Archive' => ['/articles/bonjour', [], ['Articles']],
        'the Archive itself' => ['/articles', ['Articles'], []],
        'a page under no item' => ['/photos', [], []],
    ]);

    it('marks a path under an address item that is not its Parent', function (): void {
        menuPage('Mariage', ['parent' => menuPage('Galerie')->id]);
        menuItem(['url' => '/galerie/', 'label' => ['fr' => 'Photos']]);

        $this->get('/galerie/mariage')->assertOk()->assertSee('<a href="/galerie/" aria-current="true">Photos</a>', escape: false);
    });

    it('marks nothing current in a Preview', function (): void {
        $about = menuPage('À propos');
        menuItem(['entry' => $about->id]);
        $this->get('/a-propos');
        request()->attributes->set('kami.preview', true);
        Kami::flushCache();

        expect(Kami::menu('primary')[0]->isCurrent)->toBeFalse();
    });

    it('reads a Menu in a fixed number of queries, whatever its size', function (): void {
        $parent = menuPage('Parent');

        foreach (range(1, 6) as $index) {
            $child = menuPage("Enfant {$index}", ['parent' => $parent->id]);
            menuItem(['entry' => $child->id]);
            menuItem(['url' => "/adresse-{$index}", 'label' => ['fr' => "Adresse {$index}"]]);
        }

        menuItem(['archive' => 'article']);
        DB::enableQueryLog();

        // The items; the Entries, their Translations and their type's Field values; the nested ones' Paths and ancestors.
        expect(Kami::menu('primary'))->toHaveCount(13)
            ->and(DB::getQueryLog())->toHaveCount(6);
    });

    it('reads a Menu once per request, and again after a write', function (): void {
        menuItem(['url' => '/a', 'label' => ['fr' => 'A']]);
        DB::enableQueryLog();

        Kami::menu('primary');
        Kami::menu('primary');

        expect(DB::getQueryLog())->toHaveCount(1);

        menuItem(['url' => '/b', 'label' => ['fr' => 'B']]);

        expect(Kami::menu('primary'))->toHaveCount(2);
    });

    it('throws for an undeclared Menu', function (): void {
        expect(fn () => Kami::menu('nope'))->toThrow(InvalidArgumentException::class, 'No Menu [nope] is declared under kami.menus.');
    });

    it('renders nothing for an empty Menu', function (): void {
        expect(Blade::render('<x-kami::menu handle="footer" />'))->toBe('');
    });
});

describe('deleting an Entry', function (): void {
    it('removes the Entry\'s items first through DeleteMenuItem, their children moving up one level', function (): void {
        $gone = menuPage('Partie');
        $a = menuItem(['entry' => $gone->id, 'label' => ['fr' => 'A']]);
        menuItem(['url' => '/a1', 'label' => ['fr' => 'A1'], 'parent' => $a->id]);
        menuItem(['url' => '/b', 'label' => ['fr' => 'B']]);
        menuItem(['entry' => $gone->id, 'label' => ['fr' => 'F']], 'footer');
        resolve(TrashEntry::class)->handle($gone);
        Event::fake([MenuItemDeleted::class]);
        $actor = auditUser();

        resolve(DeleteEntry::class)->handle(Entry::query()->withUnpublished()->withTrashed()->findOrFail($gone->id), actor: $actor);

        expect(menuShape())->toBe(['A1@root#1', 'B@root#2'])
            ->and(menuShape('footer'))->toBe([])
            ->and(array_values(array_filter(array_column(auditEvents(), 'event'), fn (string $event): bool => str_contains($event, 'deleted'))))->toBe(['kami.menu-items.deleted', 'kami.menu-items.deleted', 'kami.entries.force-deleted']);
        Event::assertDispatchedTimes(MenuItemDeleted::class, 2);
        Event::assertDispatched(MenuItemDeleted::class, fn (MenuItemDeleted $event): bool => $event->actor === $actor);
    });

    it('names the Menus losing a link in the admin\'s confirmation', function (): void {
        $gone = menuPage('Partie');
        $kept = menuPage('Restée');
        menuItem(['entry' => $gone->id]);
        menuItem(['entry' => $gone->id], 'footer');
        menuItem(['entry' => $kept->id], 'deep');
        resolve(TrashEntry::class)->handle($gone);
        resolve(TrashEntry::class)->handle($kept);

        entriesPage(ListEntries::class, type: 'page')
            ->filterTable('trashed', false)
            ->mountAction(TestAction::make('forceDelete')->table($gone))
            ->assertMountedActionModalSee('Menus lose their links to it: Footer menu, Main menu.');

        entriesPage(ListEntries::class, type: 'page')
            ->filterTable('trashed', false)
            ->mountAction(TestAction::make('forceDelete')->table($kept))
            ->assertMountedActionModalSee('A Menu loses its link to it: Deep.');
    });
});
