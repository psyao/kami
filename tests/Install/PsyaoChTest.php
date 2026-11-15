<?php

declare(strict_types=1);

namespace Psyao\Kami\Tests\Install\PsyaoCh;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Blocks\Image;
use Psyao\Kami\Content\Blocks\Video;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\RichText;
use Psyao\Kami\Content\Fields\Terms;
use Psyao\Kami\Content\Singleton;
use Psyao\Kami\Content\Taxonomy;
use Psyao\Kami\Facades\Kami;
use Psyao\Kami\Media\Actions\SaveMedia;
use Psyao\Kami\Menus\Actions\SaveMenuItem;
use Psyao\Kami\Redirects\Actions\SaveRedirect;

// M10's acceptance: psyao.ch's types as docs/research/site-psyao-ch.md describes them. Its home, a Page on Velox, is a Singleton here, as Kami's homes are.

final class Home extends Singleton
{
    #[\Override]
    protected ?string $prefix = '';

    public function fields(): array
    {
        return [RichText::make('body')];
    }
}

final class Post extends ContentType
{
    #[\Override]
    protected ?string $prefix = 'articles';

    #[\Override]
    protected bool $archive = true;

    #[\Override]
    protected bool $feed = true;

    public function fields(): array
    {
        return [
            RichText::make('body')->blocks([Video::class, Image::class])->code(),
            Terms::make('categories')->of(Category::class),
            Terms::make('tags')->of(Tag::class),
        ];
    }
}

final class Page extends ContentType
{
    #[\Override]
    protected ?string $prefix = '';

    #[\Override]
    protected bool $hierarchical = true;

    #[\Override]
    protected bool $orderable = true;

    public function fields(): array
    {
        return [RichText::make('body')->code()];
    }
}

final class Category extends Taxonomy
{
    #[\Override]
    protected ?string $prefix = 'category';

    #[\Override]
    protected bool $hierarchical = true;
}

final class Tag extends Taxonomy
{
    #[\Override]
    protected ?string $prefix = 'tag';
}

/**
 * Installs psyao.ch the way aguet.dev's deploy does: `kami:install`, a migration per type with Fields, `kami:install` again.
 * Its views share one layout, the `primary` Menu above and the `footer` one below.
 */
function installPsyaoCh(): void
{
    $test = test();
    $test->bootWithKamiOnAPanel();
    // kami:install migrates by itself, and CI also runs this file on MySQL, MariaDB and PostgreSQL, whose database outlives a test.
    Artisan::call('db:wipe', ['--force' => true]);
    config([
        'app.url' => 'https://www.psyao.ch',
        'kami.sites.default.url' => 'https://www.psyao.ch',
        'kami.indexable' => true,
        'kami.sites.default.locales' => ['fr' => ['region' => 'CH']],
    ]);
    $site = temporaryPath();
    $test->beforeApplicationDestroyed(fn () => File::deleteDirectory($site));
    app()->useDatabasePath("{$site}/database");
    File::ensureDirectoryExists(database_path('migrations'));
    File::ensureDirectoryExists("{$site}/views/kami");
    $layout = fn (string $main): string => <<<BLADE
        <!DOCTYPE html>
        <html lang="fr">
        <head>
            <x-kami::seo />
        </head>
        <body>
        <x-kami::menu handle="primary" />
        <main>{$main}</main>
        <x-kami::menu handle="footer" />
        </body>
        </html>
        BLADE;
    $listing = '@foreach ($entries as $post)<a href="{{ $post->url() }}">{{ $post->title }}</a>@endforeach <x-kami::pagination :paginator="$entries" />';
    $views = [
        'home' => '<h1>{{ $entry->title }}</h1>{{ $entry->body }}',
        'page' => '<x-kami::breadcrumb :entry="$entry" /><h1>{{ $entry->title }}</h1>{{ $entry->body }}',
        'post' => '<article><h1>{{ $entry->title }}</h1>{{ $entry->body }}@foreach ($entry->categories->concat($entry->tags) as $term)<a href="{{ $term->url() }}">{{ $term->title }}</a>@endforeach</article>',
        'post-archive' => '<h1>Articles</h1>' . $listing,
        'category' => '<h1>{{ $term->title }}</h1>' . $listing,
        'tag' => '<h1>{{ $term->title }}</h1>' . $listing,
    ];

    foreach ($views as $name => $main) {
        File::put("{$site}/views/kami/{$name}.blade.php", $layout($main));
    }

    View::addLocation("{$site}/views");
    $install = ['kami:install', ['--email' => 'admin@example.test', '--name' => 'Admin', '--no-interaction' => true]];

    $test->artisan(...$install)->assertSuccessful();
    config([
        'kami.types' => [Home::class, Post::class, Page::class, Category::class, Tag::class],
        'kami.blocks' => [Image::class, Video::class],
        'kami.menus' => ['primary' => ['depth' => 2], 'footer' => []],
    ]);

    foreach (['home', 'post', 'page'] as $type) {
        $test->artisan('kami:make:migration', ['type' => $type, '--no-interaction' => true])->assertSuccessful();
        // It refuses to work from a schema that its own pending migrations are about to change.
        $test->artisan('migrate')->assertSuccessful();
    }

    $test->artisan(...$install)->assertSuccessful();
    Kami::routes();
}

/** @param  list<array<mixed>>  $nodes */
function document(array ...$nodes): array
{
    return ['type' => 'doc', 'content' => $nodes];
}

/** @param  list<array<mixed>>  $marks */
function paragraph(string $text, array $marks = []): array
{
    return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text, ...($marks === [] ? [] : ['marks' => $marks])]]];
}

it('declares psyao.ch, and answers its home, Archive, cursor page, Term Archives, Feeds, nested Pages and legacy URLs', function (): void {
    installPsyaoCh();
    $this->travelTo('2026-11-01 10:00:00');
    $save = resolve(SaveEntry::class);
    $save->handle(Home::class, ['title' => 'Accueil', 'status' => 'published', 'body' => document(paragraph('Steve Aguet - Web Developer'))], Entry::query()->withUnpublished()->ofType(Home::class)->sole());
    $page = fn (string $title, ?Entry $parent = null): Entry => $save->handle(Page::class, ['title' => $title, 'status' => 'published', 'parent' => $parent?->id, 'body' => document(paragraph("{$title}, en bref."))]);
    $blocNote = $page('Bloc-note');
    $symfony = $page('Symfony', $blocNote);
    $installation = $page('Installation', $symfony);
    $contact = $page('Contact');
    $term = fn (string $type, string $title, ?Entry $parent = null): Entry => $save->handle($type, ['title' => $title, 'status' => 'published', ...($parent instanceof Entry ? ['parent' => $parent->id] : [])]);
    $development = $term(Category::class, 'Development');
    $electro = $term(Category::class, 'Electro', $term(Category::class, 'Music'));
    $php = $term(Tag::class, 'PHP');
    $screenshot = resolve(SaveMedia::class)->handle(fixture('media/pixel.png'), ['alt' => 'SpriteMe']);
    // The oldest Post, so the Archive's second page holds it.
    $sprite = $save->handle(Post::class, [
        'title' => 'Sprite CSS avec SpriteMe',
        'status' => 'published',
        'categories' => [$development->id],
        'tags' => [$php->id],
        'body' => document(
            paragraph('Un outil pour assembler ses sprites.'),
            ['type' => 'block', 'attrs' => ['handle' => 'image', 'data' => ['image' => [$screenshot->id]]]],
            ['type' => 'codeBlock', 'content' => [['type' => 'text', 'text' => 'background: url(sprite.png);']]],
            ['type' => 'block', 'attrs' => ['handle' => 'video', 'data' => ['url' => 'https://www.youtube.com/watch?v=6BoJY9mor1I']]],
            paragraph('Voir l\'installation', [['type' => 'link', 'attrs' => ['href' => "kami://entry/{$installation->id}"]]]),
        ),
    ]);

    foreach (range(1, 10) as $n) {
        $this->travel(1)->day();
        $save->handle(Post::class, ['title' => "Mix {$n}", 'status' => 'published', 'categories' => [$electro->id], 'body' => document(paragraph("Le mix numéro {$n}."))]);
    }

    $menu = resolve(SaveMenuItem::class);
    $menu->handle('primary', ['archive' => 'post', 'label' => ['fr' => 'Articles']]);
    $menu->handle('primary', ['entry' => $symfony, 'parent' => $menu->handle('primary', ['entry' => $blocNote])->id]);
    $menu->handle('footer', ['entry' => $contact]);
    $redirects = resolve(SaveRedirect::class);
    $redirects->handle('/?p=60', 301, $sprite);
    $redirects->handle('/?attachment_id', 410);
    $redirects->handle('/?feed=rss2', 301, '/feed');
    $redirects->handle('/blog', 301, '/articles');
    $redirects->handle('/2010/03/14/sprite-css_avec-spriteme', 301, $sprite);

    $this->get('/')->assertOk()->assertSeeInOrder(['Accueil', 'Steve Aguet - Web Developer']);

    $archive = $this->get('/articles')->assertOk();
    $archive->assertSeeInOrder(['Mix 10', 'Mix 9', 'Mix 2', 'Mix 1'])->assertDontSee('Sprite CSS avec SpriteMe')->assertDontSee('noindex');
    expect(preg_match('~<a href="([^"]+)" rel="next">~', (string) $archive->getContent(), $next))->toBe(1);
    $this->get(html_entity_decode($next[1]))->assertOk()
        ->assertSee('Sprite CSS avec SpriteMe')->assertDontSee('>Mix 1</a>', false)
        ->assertSee('<meta name="robots" content="noindex, follow">', false);

    $this->get('/category/development')->assertOk()->assertSeeInOrder(['<h1>Development</h1>', 'Sprite CSS avec SpriteMe'], false)->assertDontSee('Mix 1');
    $this->get('/category/music/electro')->assertOk()->assertSeeInOrder(['<h1>Electro</h1>', 'Mix 10', 'Mix 1'], false)->assertDontSee('Sprite CSS');
    $this->get('/tag/php')->assertOk()->assertSee('Sprite CSS avec SpriteMe');

    // The Site's Feed links to the home, the type's to its Archive.
    foreach (['/feed' => 'https://www.psyao.ch', '/articles/feed' => 'https://www.psyao.ch/articles'] as $feed => $link) {
        $response = $this->get($feed)->assertOk()->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8');
        $rss = simplexml_load_string((string) $response->getContent());
        expect((string) $rss['version'])->toBe('2.0');
        $channel = $rss->channel;
        expect([(string) $channel->link, (string) $channel->language, (string) $channel->children('http://www.w3.org/2005/Atom')->link->attributes()['href']])->toBe([$link, 'fr', "https://www.psyao.ch{$feed}"])
            ->and([(string) $channel->title, (string) $channel->description])->each->not->toBe('')
            ->and($channel->item)->toHaveCount(11);
        $oldest = $rss->channel->item[10];
        expect([(string) $oldest->title, (string) $oldest->link, (string) $oldest->guid, (string) $oldest->pubDate, (string) $oldest->description])
            ->toBe(['Sprite CSS avec SpriteMe', 'https://www.psyao.ch/articles/sprite-css-avec-spriteme', $sprite->id, 'Sun, 01 Nov 2026 10:00:00 +0000', 'Un outil pour assembler ses sprites. Voir l\'installation']);
    }

    $post = $this->get('/articles/sprite-css-avec-spriteme')->assertOk()->assertSeeInOrder([
        '<figure class="kami-figure">',
        'alt="SpriteMe"',
        '<pre><code>background: url(sprite.png);</code></pre>',
        'data-kami-video="https://www.youtube-nocookie.com/embed/6BoJY9mor1I?autoplay=1"',
        '<script type="module" src="https://www.psyao.ch/vendor/kami/video.js"></script>',
        '<a href="https://www.psyao.ch/bloc-note/symfony/installation">Voir l&#039;installation</a>',
        '<a href="https://www.psyao.ch/category/development">Development</a>',
        '<a href="https://www.psyao.ch/tag/php">PHP</a>',
    ], false);
    expect(substr_count((string) $post->getContent(), 'vendor/kami/video.js'))->toBe(1)
        ->and(public_path('vendor/kami/video.js'))->toBeFile();

    $this->get('/bloc-note')->assertOk()->assertSee('<h1>Bloc-note</h1>', false);
    $this->get('/bloc-note/symfony')->assertOk()->assertSee('<h1>Symfony</h1>', false);
    $this->get('/bloc-note/symfony/installation')->assertOk()->assertSeeInOrder([
        '<a href="https://www.psyao.ch/articles">Articles</a>',
        '<a href="https://www.psyao.ch/bloc-note" aria-current="true">Bloc-note</a>',
        '<a href="https://www.psyao.ch/bloc-note/symfony" aria-current="true">Symfony</a>',
        '<a href="https://www.psyao.ch/bloc-note">Bloc-note</a>',
        '<a href="https://www.psyao.ch/bloc-note/symfony">Symfony</a>',
        '<h1>Installation</h1>',
        '<a href="https://www.psyao.ch/contact">Contact</a>',
    ], false);
    $this->get('/installation')->assertNotFound();

    // An Entry target answers at its URL, an address as written.
    $this->get('/?p=60')->assertMovedPermanently()->assertHeader('Location', 'https://www.psyao.ch/articles/sprite-css-avec-spriteme');
    $this->get('/?p=60&utm_source=newsletter')->assertMovedPermanently()->assertHeader('Location', 'https://www.psyao.ch/articles/sprite-css-avec-spriteme');
    $this->get('/2010/03/14/sprite-css_avec-spriteme')->assertMovedPermanently()->assertHeader('Location', 'https://www.psyao.ch/articles/sprite-css-avec-spriteme');
    $this->get('/?feed=rss2')->assertMovedPermanently()->assertHeader('Location', '/feed');
    $this->get('/blog')->assertMovedPermanently()->assertHeader('Location', '/articles');
    $this->get('/?attachment_id=12')->assertGone();
});
