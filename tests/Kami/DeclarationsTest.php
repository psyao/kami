<?php

declare(strict_types=1);

use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Fields\Field;
use Psyao\Kami\Content\Fields\Text;
use Psyao\Kami\Content\Internal\Translation;

/** A type whose handle and Fields each test declares. */
final class Declared extends ContentType
{
    public static string $declaredHandle = 'declared';

    /** @var list<Field> */
    public static array $declared = [];

    public function handle(): string
    {
        return self::$declaredHandle;
    }

    public function fields(): array
    {
        return self::$declared;
    }
}

/** A second type, to clash with the first. */
final class Twin extends ContentType
{
    public function handle(): string
    {
        return 'declared';
    }
}

/** Runs a Kami command, the registry's first use, with these types. */
function runWithTypes(string ...$types): void
{
    config(['kami.types' => $types]);
    test()->artisan('kami:doctor')->run();
}

beforeEach(function () {
    Declared::$declaredHandle = 'declared';
    Declared::$declared = [];
});

it('accepts sound declarations', function () {
    Declared::$declared = [Text::make('summary'), Text::make('cover_alt_2')];

    expect(fn () => runWithTypes(Declared::class))->not->toThrow(InvalidArgumentException::class);
});

it('boots with an invalid declaration, so Composer\'s package:discover can install the fix', function () {
    Declared::$declaredHandle = 'NewsItem';
    // Booting the panel's routes included; running package:discover here would rewrite Testbench's shared manifest.
    $this->bootConfig = ['kami.types' => [Declared::class]];
    $this->refreshApplication();

    expect($this->app->isBooted())->toBeTrue();
});

it('fails on an invalid declaration, naming the class and the handle', function (string $handle, array $fields, array $types, string $message) {
    Declared::$declaredHandle = $handle;
    Declared::$declared = $fields;

    expect(fn () => runWithTypes(...$types))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'type handle not snake_case' => ['NewsItem', [], [Declared::class], 'Declared: the handle "NewsItem" is not snake_case'],
    'type handle with a double underscore' => ['news__item', [], [Declared::class], 'Declared: the handle "news__item" is not snake_case'],
    'type handle too long' => [str_repeat('a', 41), [], [Declared::class], 'Declared: the handle "' . str_repeat('a', 41) . '" is longer than 40 characters'],
    'duplicate type handle' => ['declared', [], [Declared::class, Twin::class], 'Twin: the handle "declared" is already the handle of Declared'],
    'Field handle not snake_case' => ['declared', [Text::make('coverAlt')], [Declared::class], 'Declared: the Field handle "coverAlt" is not snake_case'],
    'Field handle too long' => ['declared', [Text::make(str_repeat('b', 41))], [Declared::class], 'Declared: the Field handle "' . str_repeat('b', 41) . '" is longer than 40 characters'],
    'duplicate Field handle' => ['declared', [Text::make('summary'), Text::make('summary')], [Declared::class], 'Declared: the Field handle "summary" appears twice'],
    'reserved Field handle' => ['declared', [Text::make('published_at')], [Declared::class], 'Declared: the Field handle "published_at" is reserved'],
    'Field handle of a type table column' => ['declared', [Text::make('entry_id')], [Declared::class], 'Declared: the Field handle "entry_id" is reserved'],
    'Field handle of a planned SEO attribute' => ['declared', [Text::make('noindex')], [Declared::class], 'Declared: the Field handle "noindex" is reserved'],
    'Field handle of an Entry method' => ['declared', [Text::make('url')], [Declared::class], 'Declared: the Field handle "url" names a method of Psyao\\Kami\\Content\\Entry'],
]);

it('reads labels from kami-types by handle, or humanises the handle', function () {
    app('translator')->addLines([
        'kami-types.news_item.label' => 'Story',
        'kami-types.news_item.fields.cover_alt.label' => 'Alternative text',
        'kami-types.news_item.fields.cover_alt.help' => 'Describe the image.',
    ], 'en');
    Declared::$declaredHandle = 'news_item';
    $type = new Declared;

    expect($type->label())->toBe('Story')
        ->and($type->pluralLabel())->toBe('News Items')
        ->and($type->fieldLabel('cover_alt'))->toBe('Alternative text')
        ->and($type->fieldHelp('cover_alt'))->toBe('Describe the image.')
        ->and($type->fieldLabel('lead'))->toBe('Lead')
        ->and($type->fieldHelp('lead'))->toBeNull();
});

it('reserves every Translation attribute as a Field handle', function () {
    expect(Field::RESERVED_HANDLES)->toContain(...Translation::ATTRIBUTES);
});
