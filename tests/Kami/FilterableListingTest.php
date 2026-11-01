<?php

declare(strict_types=1);

use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Psyao\Kami\Content\Actions\SaveEntry;
use Psyao\Kami\Content\ContentType;
use Psyao\Kami\Content\Entry;
use Psyao\Kami\Content\Fields\Number;

uses(RefreshDatabase::class);

final class Listed extends ContentType
{
    public function fields(): array
    {
        return [
            Number::make('rank')->translatable()->filterable(),
            Number::make('stock')->sortable(),
        ];
    }
}

beforeEach(function () {
    config(['kami.types' => [Listed::class]]);
    Schema::create('kami_type_listed', function (Blueprint $table) {
        $table->ulid('entry_id');
        $table->string('locale', 20);
        $table->integer('rank')->nullable()->index();
        $table->integer('stock')->nullable()->index();
        $table->unique(['entry_id', 'locale']);
    });

    $save = resolve(SaveEntry::class);

    foreach (['Low' => [1, 30], 'Second' => [7, 20], 'First' => [7, 10]] as $title => [$rank, $stock]) {
        $entry = $save->handle(Listed::class, ['title' => $title, 'status' => 'published', 'rank' => $rank, 'stock' => $stock], locale: 'fr');
        // Another Locale's row must not reach a listing in French.
        $save->handle(Listed::class, ['title' => "{$title} en", 'status' => 'published', 'rank' => 99], $entry, 'en');
    }

    $save->handle(Listed::class, ['title' => 'Draft', 'rank' => 7, 'stock' => 5], locale: 'fr');
    $save->handle(Listed::class, ['title' => 'Only English', 'status' => 'published', 'rank' => 7, 'stock' => 1], locale: 'en');

    app()->setLocale('fr');
});

// The guide's listing, `docs/guide/content-types.md` § Listing on a Field: change them together.
it('filters on a Translatable Field and sorts on a Shared one, as the guide shows', function () {
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
