<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\Staff\RelationManagers;

use App\Audit\AuditEntry;
use App\Models\PlatformStaff;
use App\Platform\Staff\StaffPermission;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The profile history (PLATFORM_CONSOLE.md § 1): every audit entry about
 * this staff member (invitation, role grants and removals, activation,
 * deactivation), newest first. Read-only: the audit log is append-only.
 */
final class HistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'history';

    protected static ?string $title = 'History';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $staff = Filament::auth()->user();

        return $staff instanceof PlatformStaff && $staff->holdsStaffPermission(StaffPermission::StaffView);
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
                    ->state(fn (AuditEntry $record): string => $record->causer instanceof PlatformStaff ? $record->causer->name : 'System'),
                TextColumn::make('reason')
                    ->state(fn (AuditEntry $record): ?string => $record->reason ?? (is_string($record->properties['reason'] ?? null) ? $record->properties['reason'] : null))
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50]);
    }
}
