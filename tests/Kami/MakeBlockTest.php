<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Psyao\Kami\Content\Blocks\Block;
use Psyao\Kami\Content\Internal\Blocks;

beforeEach(function (): void {
    // Laravel reads the namespace by matching the app path against composer.json: read it before the path moves.
    $this->app->getNamespace();
    $this->site = sys_get_temp_dir() . '/kami-make-block-' . uniqid();
    $this->app->useAppPath("{$this->site}/app");
    config(['view.paths' => ["{$this->site}/views"]]);
});

afterEach(fn () => File::deleteDirectory($this->site));

it('writes a final Block that the registry accepts and its view renders', function (): void {
    $this->artisan('kami:make:block', ['name' => 'MadeCallout'])
        ->expectsOutputToContain('Blocks/MadeCallout.php')
        ->expectsOutputToContain('blocks/made_callout.blade.php')
        ->assertSuccessful();

    require_once app_path('Blocks/MadeCallout.php');
    $class = $this->app->getNamespace() . 'Blocks\MadeCallout';
    config(['kami.blocks' => [$class]]);
    $block = resolve(Blocks::class)->all()['made_callout'];

    expect(new ReflectionClass($class)->isFinal())->toBeTrue()
        ->and($block)->toBeInstanceOf(Block::class)
        ->and($block->summary(['text' => 'Read this']))->toBe('Read this')
        ->and($block->summary([]))->toBeNull()
        ->and(view($block->view(), ['text' => 'Read <this>'])->render())->toContain('<p>Read &lt;this&gt;</p>');
});

it('prints the lines a site adds elsewhere', function (): void {
    $class = $this->app->getNamespace() . 'Blocks\PullQuote';

    $this->artisan('kami:make:block', ['name' => 'PullQuote'])
        ->expectsOutputToContain("{$class}::class,")
        ->expectsOutputToContain("RichText::make('body')->blocks([\\{$class}::class]),")
        ->expectsOutputToContain("'pull_quote' => ['label' => 'Pull quote', 'fields' => ['text' => ['label' => 'Text']]],")
        ->assertSuccessful();
});

it('refuses to overwrite the class or the view, writing neither', function (string $existing): void {
    $paths = ['class' => app_path('Blocks/Callout.php'), 'view' => "{$this->site}/views/blocks/callout.blade.php"];
    File::ensureDirectoryExists(dirname($paths[$existing]));
    File::put($paths[$existing], 'mine');

    $this->artisan('kami:make:block', ['name' => 'Callout'])
        ->expectsOutputToContain('already exists')
        ->assertFailed();

    expect(File::get($paths[$existing]))->toBe('mine')
        ->and(File::exists($paths[$existing === 'class' ? 'view' : 'class']))->toBeFalse();
})->with(['class', 'view']);

it('refuses a name that cannot be a Block', function (string $name, string $message): void {
    $this->artisan('kami:make:block', ['name' => $name])
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(File::exists(app_path("Blocks/{$name}.php")))->toBeFalse();
})->with([
    'a path' => ['Blocks/Quote', 'not a class name'],
    'lowercase' => ['quote', 'not a class name'],
    'a PHP keyword' => ['List', 'not a class name'],
    'a name PHP reserves' => ['String', 'not a class name'],
    'the base it would extend' => ['Block', 'not a class name'],
    'too long a handle' => [str_repeat('A', 21), 'longer than 40'],
]);
