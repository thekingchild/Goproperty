<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\Customers\RelationManagers;

use App\Audit\AuditEntry;
use App\Models\PlatformStaff;
use App\Models\User;
use App\Platform\Staff\StaffPermission;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit entries about the organization, newest first: suspensions and
 * reactivations with the staff member and reason, and every other change
 * logged on it. Read-only: the audit log is append-only.
 */
final class HistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'history';

    protected static ?string $title = 'History';

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
        return $table
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('event')->badge(),
                TextColumn::make('description')->wrap(),
                TextColumn::make('causer')
                    ->label('By')
                    ->state(fn (AuditEntry $record): string => match (true) {
                        $record->causer instanceof PlatformStaff => $record->causer->name.' (staff)',
                        $record->causer instanceof User => $record->causer->name,
                        default => 'System',
                    }),
                TextColumn::make('reason')->placeholder('—')->wrap(),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50]);
    }
}
