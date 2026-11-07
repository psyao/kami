<?php

declare(strict_types=1);

namespace Workbench\App\Filament;

use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource as FilamentResource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use Psyao\Kami\Admin\KamiRoles;
use Workbench\App\Filament\UserResource\CreateUser;
use Workbench\App\Filament\UserResource\EditUser;
use Workbench\App\Filament\UserResource\ListUsers;
use Workbench\App\Models\User;

/**
 * The host's Users screen, as the starter kit ships it: Kami adds the Roles field, the host keeps the screen. Its policy takes `kami.users.manage`.
 *
 * @extends FilamentResource<User>
 */
class UserResource extends FilamentResource
{
    #[\Override]
    protected static ?string $model = User::class;

    #[\Override]
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedUsers;

    #[\Override]
    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('users.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('users.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('users.name'))->required()->maxLength(255),
            TextInput::make('email')->label(__('users.email'))->email()->required()->maxLength(255)->unique(ignoreRecord: true),
            KamiRoles::make(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('users.name'))->searchable()->sortable(),
                TextColumn::make('email')->label(__('users.email'))->searchable(),
                // One query per row: a page of Users is short.
                TextColumn::make('roles')->label(__('kami::admin.roles.label'))->badge()
                    ->state(fn (User $record): array => collect($record->kamiRoles())->map(KamiRoles::roleLabel(...))->sort()->values()->all()),
            ])
            ->recordActions([EditAction::make(), self::deleteAction()])
            ->defaultSort('name');
    }

    /** In a transaction, which holds the managers' rows until it ends; Kami's lockout guard refusing to delete the last User who may manage Users shows its message. */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->databaseTransaction()
            ->using(function (User $record, DeleteAction $action): bool {
                try {
                    return (bool) $record->delete();
                } catch (ValidationException $refused) { // @phpstan-ignore catch.neverThrown (the lockout guard throws it from the User's `deleting` hook)
                    $action->failureNotificationTitle($refused->getMessage());

                    return false;
                }
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
