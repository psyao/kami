<?php

declare(strict_types=1);

use Filament\Tables\TablesServiceProvider;
use Illuminate\Support\Arr;

/** Every key of a translation group, dotted, its values aside. */
function translationKeys(array $lines): array
{
    return array_keys(Arr::dot($lines));
}

// A Filament release adding a table line without French turns this red: Kami ships the line until Filament does (#589).
it('reads every Filament table line in French', function () {
    $english = translationKeys(trans('filament-tables::table', [], 'en'));

    expect(array_diff($english, translationKeys(trans('filament-tables::table', [], 'fr'))))->toBe([]);
});

// Kami never replaces Filament's own French, and drops a line once Filament ships it.
it('ships only the French table lines Filament lacks', function () {
    $filament = dirname((new ReflectionClass(TablesServiceProvider::class))->getFileName(), 2) . '/resources/lang/fr/table.php';

    expect(array_intersect(translationKeys(require __DIR__ . '/../../resources/lang-overrides/vendor/filament-tables/fr/table.php'), translationKeys(require $filament)))->toBe([]);
});
