<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\ImpersonationSessions\RelationManagers;

use App\Audit\AuditEntry;
use App\Models\PlatformStaff;
use App\Platform\Staff\StaffPermission;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A support session's timeline (PLATFORM_CONSOLE.md § 6): start, hand-off,
 * each page viewed, each action, each blocked attempt, the end, and the
 * business changes made meanwhile, oldest first. Read-only: the audit log
 * is append-only.
 */
final class TimelineRelationManager extends RelationManager
{
    protected static string $relationship = 'timeline';

    protected static ?string $title = 'Timeline';

    private const array EVENTS = [
        'impersonation.started' => 'Started',
        'impersonation.entered' => 'Opened',
        'impersonation.page_viewed' => 'Page viewed',
        'impersonation.action' => 'Action',
        'impersonation.blocked' => 'Blocked',
        'impersonation.refused' => 'Refused',
        'impersonation.ended' => 'Ended',
    ];

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $staff = Filament::auth()->user();

        return $staff instanceof PlatformStaff && $staff->holdsStaffPermission(StaffPermission::ImpersonationViewLog);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('Y-m-d H:i:s'),
                TextColumn::make('event')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::EVENTS[$state] ?? 'Change: '.$state)
                    ->color(fn (?string $state): string => match ($state) {
                        'impersonation.blocked', 'impersonation.refused' => 'danger',
                        'impersonation.action' => 'warning',
                        'impersonation.page_viewed' => 'gray',
                        default => 'info',
                    }),
                TextColumn::make('description')->wrap(),
                TextColumn::make('page')
                    ->label('Page')
                    ->state(fn (AuditEntry $record): ?string => is_string($record->getProperty('path')) ? $record->getProperty('path') : null)
                    ->placeholder('—'),
                TextColumn::make('result')
                    ->label('Result')
                    ->state(fn (AuditEntry $record): ?string => is_scalar($record->getProperty('status')) ? (string) $record->getProperty('status') : null)
                    ->placeholder('—'),
                TextColumn::make('ip')->label('IP')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('event')->options(self::EVENTS),
            ])
            ->defaultSort('id')
            ->paginated([50, 100]);
    }
}
