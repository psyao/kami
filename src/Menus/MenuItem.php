<?php

declare(strict_types=1);

namespace Psyao\Kami\Menus;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One link in a Menu's tree, for every Locale of its Site (ADR 0025). Written by `SaveMenuItem`, `MoveMenuItem` and `DeleteMenuItem` only; read on the front through `Kami::menu()`.
 *
 * @property string $id
 * @property string $site
 * @property string $menu The Menu's handle in `config/kami.php`.
 * @property string|null $parent_id
 * @property int $position Its rank among its siblings, from 1.
 * @property string|null $entry_id The target Entry.
 * @property string|null $archive The handle of the type whose Archive is the target.
 * @property array<string, string>|null $url The target address by Locale code; a missing one falls back to the default Locale's.
 * @property array<string, string>|null $label By Locale code; a missing one falls back to the target's title in that Locale.
 * @property bool $new_tab
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Unguarded]
#[Table(name: 'kami_menu_items')]
final class MenuItem extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'url' => 'array',
            'label' => 'array',
            'new_tab' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
