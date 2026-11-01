<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\GlobalSet;
use Psyao\Kami\Content\Singleton;
use Psyao\Kami\Content\Taxonomy;

beforeEach(function () {
    // Laravel reads the namespace by matching the app path against composer.json: read it before the path moves.
    $this->app->getNamespace();
    $this->app->useAppPath(sys_get_temp_dir() . '/kami-make-type-' . uniqid());
});

afterEach(fn () => File::deleteDirectory(app_path()));

it('writes a final class extending the base the option names', function (array $option, string $name, string $base) {
    $this->artisan('kami:make:type', ['name' => $name, ...$option])
        ->expectsOutputToContain("Content/{$name}.php")
        ->assertSuccessful();

    require_once app_path("Content/{$name}.php");
    $class = new ReflectionClass($this->app->getNamespace() . "Content\\{$name}");

    expect($class->isFinal())->toBeTrue()
        ->and($class->getParentClass()->getName())->toBe($base)
        ->and($class->newInstance()->fields())->toBe([]);
})->with([
    'a Content Type' => [[], 'MadeArticle', ContentType::class],
    'a Singleton' => [['--singleton' => true], 'MadeHome', Singleton::class],
    'a Global Set' => [['--global-set' => true], 'MadeFooter', GlobalSet::class],
    'a Taxonomy' => [['--taxonomy' => true], 'MadeTag', Taxonomy::class],
]);

it('prints the lines a site adds elsewhere, and the view only for a type with a URL', function () {
    $this->artisan('kami:make:type', ['name' => 'NewsItem'])
        ->expectsOutputToContain($this->app->getNamespace() . 'Content\NewsItem::class,')
        ->expectsOutputToContain('php artisan kami:make:migration news_item')
        ->expectsOutputToContain("'news_item' => ['label' => 'News Item', 'plural' => 'News Items'],")
        ->expectsOutputToContain('fields.<field>.label')
        ->expectsOutputToContain('resources/views/kami/news_item.blade.php')
        ->expectsOutputToContain('php artisan kami:install')
        ->assertSuccessful();

    $this->artisan('kami:make:type', ['name' => 'Home', '--singleton' => true])
        ->expectsOutputToContain("'home' => ['label' => 'Home'],")
        ->expectsOutputToContain('resources/views/kami/home.blade.php')
        ->assertSuccessful();

    $this->artisan('kami:make:type', ['name' => 'Footer', '--global-set' => true])
        ->expectsOutputToContain("'footer' => ['label' => 'Footer'],")
        ->doesntExpectOutputToContain('resources/views/kami/footer.blade.php')
        ->assertSuccessful();
});

it('refuses to overwrite a class', function () {
    File::ensureDirectoryExists(app_path('Content'));
    File::put(app_path('Content/Article.php'), 'mine');

    $this->artisan('kami:make:type', ['name' => 'Article'])
        ->expectsOutputToContain('already exists')
        ->assertFailed();

    expect(File::get(app_path('Content/Article.php')))->toBe('mine');
});

it('refuses options combined, naming them', function () {
    $this->artisan('kami:make:type', ['name' => 'Article', '--singleton' => true, '--taxonomy' => true])
        ->expectsOutputToContain('--singleton, --global-set and --taxonomy exclude one another')
        ->assertFailed();

    expect(File::exists(app_path('Content/Article.php')))->toBeFalse();
});

it('refuses a name that cannot be a type', function (string $name, string $message) {
    $this->artisan('kami:make:type', ['name' => $name])
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(File::exists(app_path("Content/{$name}.php")))->toBeFalse();
})->with([
    'a path' => ['Blog/Post', 'not a class name'],
    'lowercase' => ['article', 'not a class name'],
    'a PHP keyword' => ['List', 'not a class name'],
    'a name PHP reserves' => ['String', 'not a class name'],
    'a reserved name in capitals' => ['MIXED', 'not a class name'],
    'the base it would extend' => ['ContentType', 'not a class name'],
    'too long a handle' => [str_repeat('A', 21), 'longer than 40'],
]);
