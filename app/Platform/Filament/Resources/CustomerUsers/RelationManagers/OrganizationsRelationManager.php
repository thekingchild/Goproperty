<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\CustomerUsers\RelationManagers;

use App\Enums\OrganizationStatus;
use App\Models\PlatformStaff;
use App\Platform\Customers\Customer;
use App\Platform\Customers\CustomerUser;
use App\Platform\Filament\Resources\Customers\CustomerResource;
use App\Platform\Staff\StaffPermission;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** The organizations a user belongs to, with their roles in each. Read-only. */
final class OrganizationsRelationManager extends RelationManager
{
    protected static string $relationship = 'organizations';

    protected static ?string $title = 'Organizations';

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
        $user = $this->getOwnerRecord();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $user instanceof CustomerUser
                ? $query->addSelect(['role_names' => $user->roleNamesPerOrganization()])
                : $query)
            ->columns([
                TextColumn::make('name')->label('Organization')->searchable(['organizations.name', 'organizations.slug'])->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (OrganizationStatus $state): string => $state->label())
                    ->color(fn (OrganizationStatus $state): string => CustomerResource::statusColor($state)),
                TextColumn::make('role_names')->label('Roles there')->badge()->separator(', ')->placeholder('No role'),
                TextColumn::make('pivot.created_at')->label('Joined')->date(),
            ])
            ->recordUrl(fn (Customer $record): string => CustomerResource::getUrl('view', ['record' => $record]))
            ->defaultSort('organizations.name')
            ->paginated([25, 50]);
    }
}
