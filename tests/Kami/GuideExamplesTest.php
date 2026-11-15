<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\Actions\TrashEntry;
use Psyao\Kami\Content\Term;
use Psyao\Kami\Forms\Actions\StoreSubmission;
use Psyao\Kami\Import\Import;
use Psyao\Kami\Media\Actions\SaveMedia;
use Psyao\Kami\Redirects\Actions\SaveRedirect;
use Psyao\Kami\Tests\Fixtures\Guide\Content\Product;
use Psyao\Kami\Tests\GuideExamples;
use Workbench\App\Content\Article;
use Workbench\App\Content\Page;
use Workbench\App\Content\Project;
use Workbench\App\Content\Tag;

uses(RefreshDatabase::class);

/**
 * What a marker's setup gives its example: each receives the example's runner, which takes the variables to put in scope and
 * returns the example's namespace, and may then exercise what the example declared.
 *
 * @return array<string, Closure(Closure(array<string, mixed>=): string): void>
 */
function guideSetups(): array
{
    return [
        '' => fn (Closure $run) => $run(),
        'article' => fn (Closure $run) => $run(guideArticle()),
        // The same Article, in the Trash.
        'trashed' => function (Closure $run): void {
            $variables = guideArticle();
            resolve(TrashEntry::class)->handle($variables['entry']);

            $run($variables);
        },
        // A Page two levels down: À propos, Équipe, then it.
        'page' => function (Closure $run): void {
            $save = resolve(SaveEntry::class);
            $about = $save->handle(Page::class, ['title' => 'À propos', 'status' => 'published']);
            $team = $save->handle(Page::class, ['title' => 'Équipe', 'status' => 'published', 'parent' => $about->id]);

            $run(['entry' => $save->handle(Page::class, ['title' => 'Ada', 'status' => 'published', 'parent' => $team->id])]);
        },
        // A row of the old system an import reads.
        'post' => fn (Closure $run) => $run(['post' => (object) ['title' => 'Hello', 'author' => 'Ada Lovelace', 'html' => '<h2>Hello</h2><p>A <code>first</code> post.</p>']]),
        // A Project with its rows, its named values and a Term, an Article and a Page to relate, and an image to show.
        'project' => function (Closure $run): void {
            $save = resolve(SaveEntry::class);
            $first = $save->handle(Article::class, ['title' => 'First', 'author' => 'Ada Lovelace', 'status' => 'published']);
            $second = $save->handle(Page::class, ['title' => 'Second', 'status' => 'published']);
            $tag = $save->handle(Tag::class, ['title' => 'Design', 'status' => 'published']);
            $project = $save->handle(Project::class, [
                'title' => 'Lake house',
                'status' => 'published',
                'links' => [['label' => 'Site', 'url' => 'https://example.test']],
                'address' => ['street' => 'Rue du Lac 1', 'city' => 'Vevey'],
                'tags' => [$tag->id],
            ]);

            $run([
                'project' => $project,
                'entry' => $project,
                'first' => $first,
                'second' => $second,
                'term' => Term::query()->findOrFail($tag->id),
                'photo' => resolve(SaveMedia::class)->handle(fixture('media/pixel.png'), ['alt' => 'A pixel']),
            ]);
        },
        // A published Article to send visitors to, and a Redirect from `/blog`.
        'redirect' => function (Closure $run): void {
            resolve(SaveRedirect::class)->handle('/blog', 301, '/articles');

            $run(['post' => guideArticle()['entry']]);
        },
        // An administrator signed in, and a User without a Role.
        'roles' => function (Closure $run): void {
            signInWith('administrator');

            $run(['user' => auditUser('Grace Hopper')]);
        },
        // Rows of a source to import.
        'rows' => fn (Closure $run) => $run(['rows' => [['title' => 'Un', 'author' => 'Ada Lovelace'], ['title' => 'Deux', 'author' => 'Ada Lovelace']]]),
        // A published page and a visitor's values for the contact Form, as one Submission already stored them.
        'contact' => function (Closure $run): void {
            $page = resolve(SaveEntry::class)->handle(Page::class, ['title' => 'Contact', 'status' => 'published']);
            $validated = ['name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hello from the lake.'];
            resolve(StoreSubmission::class)->handle('contact', $validated, locale: 'fr', entry: $page);

            $run(['page' => $page, 'validated' => $validated, 'email' => $validated['email']]);
        },
        // An image, its User signed in with a photo uploaded, a PDF, and a file waiting in the storage, on disks of the test's own.
        'media' => function (Closure $run): void {
            app()->useStoragePath(temporaryPath());
            Storage::fake('local');
            Storage::fake('public');
            File::ensureDirectoryExists(storage_path('import'));
            File::copy(fixture('media/Holiday Photo.JPG'), storage_path('import/team.jpg'));
            $user = auditUser();
            $request = Request::create('/media', 'POST', files: ['photo' => uploadedFixture('pixel.png')]);
            $request->setUserResolver(fn () => $user);
            $save = resolve(SaveMedia::class);
            $media = $save->handle(fixture('media/Holiday Photo.JPG'), ['alt' => 'The team on the terrace']);

            $run(['media' => $media, 'id' => $media->id, 'request' => $request, 'brochure' => $save->handle(fixture('media/brochure.pdf'))]);
        },
        // Two Pages to put in a Menu.
        'menu' => function (Closure $run): void {
            $save = resolve(SaveEntry::class);

            $run(['aboutPage' => $save->handle(Page::class, ['title' => 'À propos', 'status' => 'published']), 'teamPage' => $save->handle(Page::class, ['title' => 'Équipe', 'status' => 'published'])]);
        },
        // The old Velox site: its database with a post in HTML and a message, a file copied next to the dump, and an open run.
        // An importer the example declares then runs as a deploy runs it: #587's RichText example failed there.
        'velox' => function (Closure $run): void {
            app()->useStoragePath(temporaryPath());
            Storage::fake('local');
            Storage::fake('public');
            File::ensureDirectoryExists(storage_path('import/velox'));
            File::copy(fixture('media/pixel.png'), storage_path('import/velox/pixel.png'));
            config(['database.connections.velox' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
            Schema::connection('velox')->create('posts', function (Blueprint $table): void {
                $table->id();
                $table->string('title_fr');
                $table->string('slug_fr');
                $table->text('body_fr')->nullable();
                $table->string('title_en');
                $table->string('slug_en');
                $table->text('body_en')->nullable();
                $table->string('author');
                $table->dateTime('published_at');
                $table->timestamps();
            });
            Schema::connection('velox')->create('contact_messages', function (Blueprint $table): void {
                $table->id();
                $table->string('subject');
                $table->string('email');
                $table->text('body');
                $table->string('locale');
                $table->dateTime('created_at');
                $table->dateTime('read_at')->nullable();
            });
            $at = '2019-03-01 08:00:00';
            DB::connection('velox')->table('posts')->insert([
                'title_fr' => 'Bonjour', 'slug_fr' => 'bonjour', 'body_fr' => '<p>Un <strong>premier</strong> article.</p>',
                'title_en' => 'Hello', 'slug_en' => 'hello', 'body_en' => null,
                'author' => 'Ada Lovelace', 'published_at' => $at, 'created_at' => $at, 'updated_at' => $at,
            ]);
            DB::connection('velox')->table('contact_messages')->insert(['subject' => 'Bonjour', 'email' => 'ada@example.test', 'body' => 'Hello from the lake.', 'locale' => 'fr', 'created_at' => $at, 'read_at' => null]);

            $namespace = $run([
                'import' => Import::start('Velox'),
                'file' => (object) ['id' => 7, 'path' => 'pixel.png', 'alt_fr' => 'Un pixel', 'alt_en' => 'A pixel', 'credit' => 'Ada Lovelace', 'created_at' => $at, 'updated_at' => $at],
                'link' => (object) ['post_id' => 1],
            ]);

            if (class_exists($command = "{$namespace}\\ImportVelox")) {
                Artisan::registerCommand(resolve($command));

                if (Artisan::call('app:import:velox') !== 0) {
                    throw new RuntimeException('app:import:velox failed: ' . Artisan::output());
                }
            }
        },
        // The guides' Product, with its table.
        'product' => function (Closure $run): void {
            config(['kami.types' => [...config()->array('kami.types'), Product::class]]);
            Schema::create('kami_type_product', function (Blueprint $table): void {
                $table->ulid('entry_id');
                $table->string('locale', 20);
                $table->integer('rank')->nullable()->index();
                $table->integer('stock')->nullable()->index();
                $table->unique(['entry_id', 'locale']);
            });

            $run();
        },
    ];
}

/**
 * An Article published in French with a Draft in English, its User signed in, the values of an edit loaded a moment ago, an image
 * for its social card, and a Page to send its visitors to once it is deleted.
 *
 * @return array<string, mixed>
 */
function guideArticle(): array
{
    app()->useStoragePath(temporaryPath());
    Storage::fake('local');
    Storage::fake('public');
    $user = auditUser();
    test()->actingAs($user);
    $save = resolve(SaveEntry::class);
    $entry = $save->handle(Article::class, ['title' => 'Hello world', 'summary' => 'A first article.', 'author' => 'Ada Lovelace', 'status' => 'published'], locale: 'fr');
    $save->handle(Article::class, ['title' => 'Hello world'], $entry, 'en');

    return [
        'entry' => $entry,
        'id' => $entry->id,
        'values' => ['title' => 'Hello again'],
        'loadedAt' => now(),
        'media' => resolve(SaveMedia::class)->handle(fixture('media/pixel.png'), ['alt' => 'A pixel']),
        'home' => $save->handle(Page::class, ['title' => 'Accueil', 'status' => 'published']),
        'user' => $user,
        'request' => request(),
    ];
}

/** Runs a marked example through its setup; whatever it throws comes back naming the example. */
function runGuideExample(string $name, ?string $setup, string $code): void
{
    $prepare = guideSetups()[$setup ?? ''] ?? throw new LogicException("{$name}: no setup is named `{$setup}`.");

    try {
        $prepare(fn (array $variables = []): string => GuideExamples::evaluate($name, $code, $variables));
    } catch (Throwable $exception) {
        throw new RuntimeException("{$name} fails: {$exception->getMessage()}", previous: $exception);
    }
}

it('runs every example a guide marks', function (string $name, ?string $setup, string $code): void {
    runGuideExample($name, $setup, $code);
})->throwsNoExceptions()->with(fn (): array => collect(glob(dirname(__DIR__, 2) . '/docs/guide/*.md') ?: [])->flatMap(GuideExamples::in(...))->all());

it('fails an example that throws, naming its guide and line', function (): void {
    $example = GuideExamples::in(fixture('guide-examples/broken.md'))['broken.md:6'];

    expect(fn () => runGuideExample(...$example))->toThrow(RuntimeException::class, 'broken.md:6 fails: ');
});

it('refuses a marker that does not stand right before a php fence, naming its line', function (): void {
    expect(fn () => GuideExamples::in(fixture('guide-examples/misplaced.md')))->toThrow(LogicException::class, 'misplaced.md:6: a marker reads');
});
