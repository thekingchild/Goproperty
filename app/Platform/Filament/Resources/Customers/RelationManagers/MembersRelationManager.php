<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\Customers\RelationManagers;

use App\Models\PlatformStaff;
use App\Platform\Customers\Customer;
use App\Platform\Customers\CustomerUser;
use App\Platform\Filament\Resources\CustomerUsers\CustomerUserResource;
use App\Platform\Staff\StaffPermission;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The organization's users and their roles there (Console → Customers).
 * Read-only: members are managed by the organization itself.
 */
final class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = 'Users';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $staff = Filament::auth()->user();

        return $staff instanceof PlatformStaff && $staff->holdsStaffPermission(StaffPermission::CustomersView);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $organizationId = $this->getOwnerRecord()->getKey();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->addSelect([
                'role_names' => Customer::roleNamesColumn(is_int($organizationId) || is_string($organizationId) ? $organizationId : 0),
            ]))
            ->columns([
                TextColumn::make('name')
                    ->searchable(['users.name', 'users.email', 'users.phone'])
                    ->sortable()
                    ->description(fn (CustomerUser $record): ?string => $record->email),
                TextColumn::make('phone')->placeholder('—'),
                TextColumn::make('role_names')
                    ->label('Roles here')
                    ->badge()
                    ->separator(', ')
                    ->placeholder('No role'),
                TextColumn::make('pivot.created_at')->label('Joined')->date(),
            ])
            ->recordUrl(fn (CustomerUser $record): string => CustomerUserResource::getUrl('view', ['record' => $record]))
            ->defaultSort('users.name')
            ->paginated([25, 50]);
    }
}
