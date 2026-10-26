<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\StaffRoles;

use App\Models\StaffRole;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\StaffRoles\Pages\CreateStaffRole;
use App\Platform\Filament\Resources\StaffRoles\Pages\EditStaffRole;
use App\Platform\Filament\Resources\StaffRoles\Pages\ListStaffRoles;
use App\Platform\Staff\StaffPermission;
use App\Platform\Staff\StaffRoles;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Console → Staff → Roles (PLATFORM_CONSOLE.md § 1): default and custom
 * roles, made of permissions from the catalogue. Create, rename (custom
 * roles), change permissions, deactivate once nobody holds the role.
 * `super-admin` is shown but never editable. Protected permissions wait
 * for a second super-admin's approval. StaffRolePolicy decides what is
 * offered; StaffRoles enforces it.
 */
final class StaffRoleResource extends Resource
{
    use ActsAsStaff;

    protected static ?string $model = StaffRole::class;

    protected static ?string $slug = 'staff-roles';

    protected static ?string $modelLabel = 'role';

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isGloballySearchable = false;

    protected static string|UnitEnum|null $navigationGroup = 'Staff';

    protected static ?string $navigationLabel = 'Roles';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount(['permissions', 'users']);
    }

    public static function form(Schema $schema): Schema
    {
        $groups = [];

        foreach (StaffPermission::grouped() as $area => $options) {
            $groups[] = CheckboxList::make("permissions_by_area.{$area}")
                ->label($area)
                ->options($options)
                ->bulkToggleable()
                ->columns(2);
        }

        return $schema->components([
            Section::make('Role')->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(60)
                    ->disabled(fn (?StaffRole $record): bool => $record !== null && $record->is_default)
                    ->helperText(fn (?StaffRole $record): ?string => $record !== null && $record->is_default ? 'Default roles keep their names.' : null),
                Textarea::make('description')->maxLength(255)->rows(2),
            ]),
            Section::make('Permissions')
                ->description('Protected permissions can only be added by a super-admin and take effect after a second super-admin approves.')
                ->schema($groups),
        ]);
    }

    /**
     * The checkbox state (permissions grouped by area) as a flat list.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function permissionsFromForm(array $data): array
    {
        $names = [];

        foreach ((array) ($data['permissions_by_area'] ?? []) as $selected) {
            foreach ((array) $selected as $name) {
                $names[] = (string) $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  list<string>  $names
     * @return array<string, list<string>>
     */
    public static function permissionsToForm(array $names): array
    {
        $byArea = [];

        foreach ($names as $name) {
            $permission = StaffPermission::tryFrom($name);

            if ($permission !== null) {
                $byArea[$permission->area()][] = $permission->value;
            }
        }

        return $byArea;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->description(fn (StaffRole $record): ?string => $record->description),
                IconColumn::make('is_default')->label('Default')->boolean(),
                IconColumn::make('is_protected')->label('Protected')->boolean(),
                IconColumn::make('active')->boolean(),
                TextColumn::make('permissions_count')->label('Permissions'),
                TextColumn::make('users_count')->label('Staff'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('deactivate')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Only a role nobody holds can be deactivated. Its permissions stop counting at once.')
                    ->authorize(fn (StaffRole $record): bool => self::actor()->can('deactivate', $record))
                    ->action(fn (StaffRole $record): bool => self::attempt(function () use ($record): string {
                        app(StaffRoles::class)->deactivateRole(self::actor(), $record);

                        return "{$record->name} deactivated.";
                    })),
                Action::make('reactivate')
                    ->requiresConfirmation()
                    ->authorize(fn (StaffRole $record): bool => self::actor()->can('reactivate', $record))
                    ->action(fn (StaffRole $record): bool => self::attempt(function () use ($record): string {
                        app(StaffRoles::class)->reactivateRole(self::actor(), $record);

                        return "{$record->name} reactivated.";
                    })),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaffRoles::route('/'),
            'create' => CreateStaffRole::route('/create'),
            'edit' => EditStaffRole::route('/{record}/edit'),
        ];
    }
}
