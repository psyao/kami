<?php

declare(strict_types=1);

use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Entries;
use Psyao\Kami\Content\Fields\Group;
use Psyao\Kami\Content\Fields\Number;
use Psyao\Kami\Content\Fields\Repeater;
use Psyao\Kami\Content\Fields\Text;

uses(RefreshDatabase::class);

final class Listed extends ContentType
{
    public function fields(): array
    {
        return [
            Number::make('rank')->translatable()->filterable(),
            Number::make('stock')->sortable(),
            Number::make('weight')->filterable(),
            Text::make('code')->translatable()->filterable()->sortable(),
            Entries::make('related')->of(Listed::class),
            Group::make('place', [Text::make('city')]),
            Repeater::make('lines', [Text::make('label')]),
        ];
    }
}

final class Stocked extends ContentType
{
    #[Override]
    protected bool $translatable = false;

    public function fields(): array
    {
        return [Text::make('sku')->filterable()];
    }
}

beforeEach(function (): void {
    $this->freezeTime();
    config(['kami.types' => [Listed::class, Stocked::class]]);
    Schema::create('kami_type_listed', function (Blueprint $table): void {
        $table->ulid('entry_id');
        $table->string('locale', 20);
        $table->integer('rank')->nullable()->index();
        $table->integer('stock')->nullable()->index();
        $table->integer('weight')->nullable()->index();
        $table->string('code')->nullable()->index();
        $table->json('place')->nullable();
        $table->json('lines')->nullable();
        $table->unique(['entry_id', 'locale']);
    });
    Schema::create('kami_type_stocked', function (Blueprint $table): void {
        $table->ulid('entry_id');
        $table->string('locale', 20);
        $table->string('sku')->nullable()->index();
        $table->unique(['entry_id', 'locale']);
    });

    $save = resolve(SaveEntry::class);

    foreach (['Low' => [1, 30, 2, 'c'], 'Second' => [7, 20, 2, 'a'], 'First' => [7, 10, 3, 'b']] as $title => [$rank, $stock, $weight, $code]) {
        $entry = $save->handle(Listed::class, ['title' => $title, 'status' => 'published', 'rank' => $rank, 'stock' => $stock, 'weight' => $weight, 'code' => $code], locale: 'fr');
        // Another Locale's row must not reach a listing in French.
        $save->handle(Listed::class, ['title' => "{$title} en", 'status' => 'published', 'rank' => 99, 'code' => "z{$code}"], $entry, 'en');
    }

    $save->handle(Listed::class, ['title' => 'Draft', 'rank' => 7, 'stock' => 5], locale: 'fr');
    $save->handle(Listed::class, ['title' => 'Only English', 'status' => 'published', 'rank' => 7, 'stock' => 1], locale: 'en');

    app()->setLocale('fr');
});

// The guide's listing, `docs/guide/content-types.md` § Listing on a Field: change them together.
it('filters on a Translatable Field and sorts on a Shared one, as the guide shows', function (): void {
    $entries = Entry::query()->ofType(Listed::class)
        ->select('kami_entries.*')
        ->join('kami_type_listed as translated', fn (JoinClause $join) => $join->on('translated.entry_id', '=', 'kami_entries.id')->where('translated.locale', app()->getLocale()))
        ->join('kami_type_listed as shared', fn (JoinClause $join) => $join->on('shared.entry_id', '=', 'kami_entries.id')->where('shared.locale', '*'))
        ->where('translated.rank', 7)
        ->orderBy('shared.stock')
        ->get();

    expect($entries->pluck('title')->all())->toBe(['First', 'Second'])
        ->and($entries->pluck('rank')->all())->toBe([7, 7])
        ->and($entries->pluck('stock')->all())->toBe([10, 20]);
});

it('filters and sorts through the scopes, in the read Locale, leaving Drafts out', function (): void {
    $entries = Entry::query()->ofType(Listed::class)->whereField('rank', 7)->orderByField('stock')->get();

    expect($entries->pluck('title')->all())->toBe(['First', 'Second'])
        ->and($entries->pluck('stock')->all())->toBe([10, 20]);
});

it('filters on a Shared Field and sorts on a Translatable one', function (): void {
    $entries = Entry::query()->ofType(Listed::class)->whereField('weight', 2)->orderByField('code', 'desc')->get();

    expect($entries->pluck('title')->all())->toBe(['Low', 'Second']);
});

it('reads the \'*\' row of an untranslatable type, whatever the Field declares', function (): void {
    $save = resolve(SaveEntry::class);
    $save->handle(Stocked::class, ['title' => 'Bolt', 'status' => 'published', 'sku' => 'B-1']);
    $save->handle(Stocked::class, ['title' => 'Nut', 'status' => 'published', 'sku' => 'N-1']);

    expect(Entry::query()->ofType(Stocked::class)->whereField('sku', 'N-1')->pluck('id'))->toHaveCount(1);
});

it('takes an operator, a direction, and reads another Locale\'s row in that Locale', function (): void {
    $french = Entry::query()->ofType(Listed::class)->whereField('rank', '>=', 1)->orderByField('stock', 'desc')->get();
    $english = Entry::query()->ofType('listed')->inLocale('en')->whereField('rank', 99)->orderByField('stock')->get();

    expect($french->pluck('title')->all())->toBe(['Low', 'Second', 'First'])
        ->and($english->pluck('title')->all())->toBe(['First en', 'Second en', 'Low en']);
});

it('joins each row once, however many Fields read it, and keeps a select of its own', function (): void {
    $query = Entry::query()->ofType(Listed::class)->select('kami_entries.id')
        ->whereField('rank', 7)->whereField('code', '<', 'c')->orderByField('code')->whereField('weight', 3)->orderByField('stock');

    expect($query->getQuery()->joins)->toHaveCount(2)
        ->and($query->getQuery()->columns)->toBe(['kami_entries.id'])
        ->and($query->get()->pluck('id'))->toHaveCount(1);
});

it('refuses a Field it cannot reach, naming it', function (Closure $scope, string $message): void {
    expect(fn () => $scope(Entry::query()->ofType(Listed::class)))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'undeclared' => [fn ($query) => $query->whereField('missing', 1), '"missing"'],
    'a relation' => [fn ($query) => $query->whereField('related', 1), 'relation "related"'],
    'a Media Field' => [fn ($query) => $query->whereField('seo_image', 1), 'Media Field "seo_image"'],
    'inside a Group' => [fn ($query) => $query->orderByField('city'), '"city" is inside "place"'],
    'inside a Repeater' => [fn ($query) => $query->whereField('label', 'x'), '"label" is inside "lines"'],
    'not filterable' => [fn ($query) => $query->whereField('stock', 1), '"stock" is not ->filterable()'],
    'not sortable' => [fn ($query) => $query->orderByField('rank'), '"rank" is not ->sortable()'],
]);

it('needs ofType() first, and its Locale fixed before a Field joins', function (): void {
    expect(fn () => Entry::query()->whereField('rank', 7))->toThrow(LogicException::class, 'ofType()')
        ->and(fn () => Entry::query()->ofType('nope')->whereField('rank', 7))->toThrow(LogicException::class, '"nope"')
        ->and(fn () => Entry::query()->ofType(Listed::class)->whereField('rank', 7)->inLocale('en'))->toThrow(LogicException::class, 'inLocale()');
});
