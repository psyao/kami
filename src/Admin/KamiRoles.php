<?php

declare(strict_types=1);

namespace Psyao\Kami\Admin;

use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use Psyao\Kami\Users\Actions\AssignRoles;
use Psyao\Kami\Users\KamiUser;

/**
 * The "Kami Roles" field of a User form: the Roles `config/kami.php` declares, by their `kami-roles` labels, saved through `AssignRoles` with the User's save.
 * Shown only to a User holding `kami.users.manage`; `AssignRoles`' refusal, the lockout guard's included, shows as the field's error.
 */
final class KamiRoles extends CheckboxList
{
    public static function getDefaultName(): string
    {
        return 'kami_roles';
    }

    /** `kami-roles.{handle}`, or the handle itself, for a Role without a label or no longer declared. */
    public static function roleLabel(string $handle): string
    {
        $key = "kami-roles.{$handle}";

        return Lang::has($key) && is_string($label = __($key)) ? $label : $handle;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('kami::admin.roles.label'));

        // A Role held but no longer declared stays listed, so a save keeps it rather than revoking it unseen.
        $this->options(function (?Model $record): array {
            $handles = array_unique([
                ...array_map(fn (int | string $handle): string => (string) $handle, array_keys(Config::array('kami.roles', []))),
                ...($record instanceof KamiUser ? $record->kamiRoles() : []),
            ]);

            return array_combine($handles, array_map(self::roleLabel(...), $handles));
        });

        $this->visible(fn (): bool => Filament::auth()->user()?->can('kami.users.manage') ?? false);

        $this->loadStateFromRelationshipsUsing(static function (KamiRoles $component, ?Model $record): void {
            $component->state($record instanceof KamiUser ? $record->kamiRoles() : []);
        });

        $this->saveRelationshipsUsing(static function (KamiRoles $component, Model & KamiUser $record, ?array $state): void {
            try {
                resolve(AssignRoles::class)->handle($record, array_values(array_filter($state ?? [], is_string(...))), Filament::auth()->user());
            } catch (ValidationException $refused) {
                throw ValidationException::withMessages([(string) $component->getStatePath() => Arr::flatten($refused->errors())]);
            }
        });

        // Saved by `AssignRoles`, never as a column of the User.
        $this->dehydrated(false);
    }
}
