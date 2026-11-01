<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\BackupOperations;

use App\Backup\Operations\BackupOperation;
use App\Backup\Operations\BackupOperations;
use App\Backup\Operations\OperationKind;
use App\Backup\Operations\OperationStatus;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\BackupOperations\Pages\ListBackupOperations;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Console → Operations → Backup activity (P06-10): what staff asked the
 * backups console to do (and the monthly verify), with its state and
 * result. A queued request can be withdrawn before the scheduler's minute
 * tick starts it; a production restore waiting for approval is decided in
 * Staff → Approvals.
 */
final class BackupOperationResource extends Resource
{
    use ActsAsStaff;

    protected static ?string $model = BackupOperation::class;

    protected static ?string $slug = 'backup-activity';

    protected static ?string $modelLabel = 'backup operation';

    protected static ?string $pluralModelLabel = 'Backup activity';

    protected static bool $isGloballySearchable = false;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Backup activity';

    protected static ?int $navigationSort = 21;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['requestedBy', 'approvedBy', 'backupRun']);
    }

    public static function getNavigationBadge(): ?string
    {
        $open = BackupOperation::query()->whereIn('status', OperationStatus::openValues())->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Asked')->dateTime('j M Y H:i', 'Africa/Lagos')->sortable(),
                TextColumn::make('kind')->badge()->formatStateUsing(fn (OperationKind $state): string => $state->label())->color(fn (OperationKind $state): string => $state->color()),
                TextColumn::make('subject')
                    ->label('Of')
                    ->state(fn (BackupOperation $record): string => match ($record->kind) {
                        OperationKind::Backup => $record->backup_type === 'db' ? 'Database' : 'Files',
                        OperationKind::Drill => $record->drill_date?->format('j M Y') ?? '—',
                        default => $record->backupRun->filename ?? '—',
                    })
                    ->wrap(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (OperationStatus $state): string => $state->label())->color(fn (OperationStatus $state): string => $state->color()),
                TextColumn::make('requestedBy.name')->label('Asked by')->placeholder('The schedule')
                    ->description(fn (BackupOperation $record): ?string => $record->approvedBy !== null ? "Approved by {$record->approvedBy->name}" : null),
                TextColumn::make('reason')->label('Why')->wrap()->limit(120)->placeholder('—'),
                TextColumn::make('result')->wrap()->limit(300)->tooltip(fn (BackupOperation $record): ?string => $record->result)->placeholder('—'),
                TextColumn::make('finished_at')->label('Finished')->dateTime('j M Y H:i', 'Africa/Lagos')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('kind')->options(collect(OperationKind::cases())->mapWithKeys(fn (OperationKind $kind): array => [$kind->value => $kind->label()])->all()),
                SelectFilter::make('status')->options(collect(OperationStatus::cases())->mapWithKeys(fn (OperationStatus $status): array => [$status->value => $status->label()])->all()),
            ])
            ->recordActions([
                Action::make('cancel')
                    ->label('Withdraw')
                    ->color('danger')
                    ->icon(Heroicon::OutlinedXMark)
                    ->authorize(fn (BackupOperation $record): bool => self::actor()->can('cancel', $record))
                    ->requiresConfirmation()
                    ->action(fn (BackupOperation $record): bool => self::attempt(function () use ($record): string {
                        app(BackupOperations::class)->cancel(self::actor(), $record);

                        return 'Withdrawn.';
                    })),
            ])
            ->poll('15s')
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListBackupOperations::route('/')];
    }
}
