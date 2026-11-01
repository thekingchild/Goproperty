<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\Backups;

use App\Backup\BackupRun;
use App\Backup\Operations\BackupOperations;
use App\Backup\Operations\DownloadLinks;
use App\Platform\Console\StepUp;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\Backups\Pages\ListBackups;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;
use UnitEnum;

/**
 * Console → Operations → Backups (P06-10; PLATFORM_CONSOLE.md § 4): every
 * backup at every destination, newest first. **Back up now** (database or
 * files, `backups.run`) and **Record a drill** sit above the list; on a
 * backup, **Download** (a signed link valid 15 minutes, audited,
 * `backups.download`), **Verify** (`backups.verify`), **Restore to staging**
 * (`backups.restore_staging`) and **Restore to production**
 * (`backups.restore_production`: password and code, the backup's name
 * typed, a reason, then a super-admin's approval in Staff → Approvals).
 * Requests run at the scheduler's next minute and show in Backup activity.
 */
final class BackupResource extends Resource
{
    use ActsAsStaff;

    protected static ?string $model = BackupRun::class;

    protected static ?string $slug = 'backups';

    protected static ?string $modelLabel = 'backup';

    protected static bool $isGloballySearchable = false;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Backups';

    protected static ?int $navigationSort = 20;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('destination');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('started_at')->label('Started')->dateTime('j M Y H:i', 'Africa/Lagos')->sortable()->placeholder('—'),
                TextColumn::make('type')->badge()->formatStateUsing(fn (string $state): string => $state === 'db' ? 'Database' : 'Files')->color(fn (string $state): string => $state === 'db' ? 'info' : 'gray'),
                TextColumn::make('destination.name')->label('Destination')->placeholder('None'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        'success' => 'success',
                        'failed' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('filename')->label('File')->placeholder('—')->description(fn (BackupRun $record): ?string => $record->status === 'failed' ? $record->notes : null)->wrap(),
                TextColumn::make('size_bytes')->label('Size')->formatStateUsing(fn (?int $state): string => $state !== null ? Number::fileSize($state, 1) : '—')->placeholder('—'),
                TextColumn::make('triggered_by')->label('By')->formatStateUsing(fn (string $state): string => match ($state) {
                    'schedule' => 'Nightly',
                    'safety' => 'Before a restore',
                    default => 'Staff',
                }),
                TextColumn::make('verified_at')->label('Verified')->dateTime('j M Y', 'Africa/Lagos')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('type')->options(['db' => 'Database', 'files' => 'Files']),
                SelectFilter::make('status')->options(['success' => 'Success', 'failed' => 'Failed', 'running' => 'Running']),
                SelectFilter::make('backup_destination_id')->label('Destination')->relationship('destination', 'name'),
            ])
            ->headerActions([
                self::backUpNow('db', 'Back up database now'),
                self::backUpNow('files', 'Back up files now'),
                Action::make('recordDrill')
                    ->label('Record a drill')
                    ->color('gray')
                    ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                    ->authorize(fn (): bool => self::actor()->can('recordDrill', BackupRun::class))
                    ->modalDescription('The quarterly restore drill done by hand on staging (docs/runbooks/restore.md): when it was and how it went.')
                    ->schema([
                        TextInput::make('date')->type('date')->required()->default(now('Africa/Lagos')->toDateString()),
                        Radio::make('passed')->label('Outcome')->options(['1' => 'Passed', '0' => 'Failed'])->required()->default('1'),
                        Textarea::make('notes')->label('What was restored and checked')->required()->minLength(BackupOperations::REASON_MIN)->maxLength(BackupOperations::REASON_MAX),
                    ])
                    ->action(fn (array $data): bool => self::attempt(function () use ($data): string {
                        app(BackupOperations::class)->recordDrill(self::actor(), (string) $data['date'], (string) $data['passed'] === '1', (string) $data['notes']);

                        return 'Drill recorded.';
                    })),
            ])
            ->recordActions([
                Action::make('download')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->authorize(fn (BackupRun $record): bool => self::actor()->can('download', $record))
                    ->url(fn (BackupRun $record): string => app(DownloadLinks::class)->for($record)),
                Action::make('verify')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->color('gray')
                    ->authorize(fn (BackupRun $record): bool => self::actor()->can('verify', $record))
                    ->requiresConfirmation()
                    ->modalDescription('Restores this backup into the verify database, checks every table and the migration history, then empties the verify database again. It runs within a minute; the result shows in Backup activity.')
                    ->action(fn (BackupRun $record): bool => self::attempt(function () use ($record): string {
                        app(BackupOperations::class)->queueVerify(self::actor(), $record);

                        return 'Verify queued: it starts within a minute.';
                    })),
                Action::make('restoreStaging')
                    ->label('Restore to staging')
                    ->icon(Heroicon::OutlinedBeaker)
                    ->color('warning')
                    ->authorize(fn (BackupRun $record): bool => self::actor()->can('restoreStaging', $record))
                    ->modalDescription(fn (): string => 'Replaces everything in the staging database with this backup, for a drill or an investigation. The live site is not touched.')
                    ->schema([Textarea::make('reason')->label('Why')->required()->minLength(BackupOperations::REASON_MIN)->maxLength(BackupOperations::REASON_MAX)])
                    ->action(fn (BackupRun $record, array $data): bool => self::attempt(function () use ($record, $data): string {
                        app(BackupOperations::class)->queueStagingRestore(self::actor(), $record, (string) $data['reason']);

                        return 'Restore to staging queued: it starts within a minute.';
                    })),
                Action::make('restoreProduction')
                    ->label('Restore to production')
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->color('danger')
                    ->authorize(fn (BackupRun $record): bool => self::actor()->can('restoreProduction', $record))
                    ->modalHeading('Restore production from this backup')
                    ->modalDescription(fn (BackupRun $record): string => 'Everything written since this backup will be replaced. A super-admin must approve in Staff → Approvals; then the site goes into maintenance mode, a safety backup is taken, the backup is restored and everyone is signed out. Confirm it is you, and type '.app(BackupOperations::class)->confirmationPhrase($record).' to confirm.')
                    ->modalSubmitActionLabel('Ask for approval')
                    ->schema(fn (BackupRun $record): array => array_values(array_filter([
                        TextInput::make('password')->label('Your password')->password()->required(),
                        app(StepUp::class)->needsCode(self::actor()) ? TextInput::make('code')->label('Code from your authenticator app')->required()->length(6) : null,
                        TextInput::make('confirmation')->label('Type the backup\'s name')->required()->placeholder(app(BackupOperations::class)->confirmationPhrase($record)),
                        Textarea::make('reason')->label('Why')->required()->minLength(BackupOperations::REASON_MIN)->maxLength(BackupOperations::REASON_MAX),
                    ])))
                    ->action(function (BackupRun $record, array $data): bool {
                        $problem = app(StepUp::class)->confirm(self::actor(), (string) ($data['password'] ?? ''), isset($data['code']) ? (string) $data['code'] : null);

                        if ($problem !== null) {
                            Notification::make()->title($problem)->danger()->send();

                            return false;
                        }

                        return self::attempt(function () use ($record, $data): string {
                            app(BackupOperations::class)->requestProductionRestore(self::actor(), $record, (string) $data['confirmation'], (string) $data['reason']);

                            return 'Approval requested: a super-admin decides it in Staff → Approvals.';
                        });
                    }),
            ])
            ->defaultSort('id', 'desc');
    }

    private static function backUpNow(string $type, string $label): Action
    {
        return Action::make('backUp'.ucfirst($type))
            ->label($label)
            ->icon(Heroicon::OutlinedPlay)
            ->authorize(fn (): bool => self::actor()->can('run', BackupRun::class))
            ->requiresConfirmation()
            ->modalDescription(($type === 'db' ? 'The database' : 'The uploaded files').' are backed up to every active destination that takes '.($type === 'db' ? 'database' : 'files').' backups, within a minute. The result shows here and in Backup activity.')
            ->action(fn (): bool => self::attempt(function () use ($type): string {
                app(BackupOperations::class)->queueBackup(self::actor(), $type);

                return 'Backup queued: it starts within a minute.';
            }));
    }

    public static function getPages(): array
    {
        return ['index' => ListBackups::route('/')];
    }
}
