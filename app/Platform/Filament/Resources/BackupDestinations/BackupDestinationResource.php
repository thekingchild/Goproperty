<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\BackupDestinations;

use App\Backup\BackupDestination;
use App\Backup\Operations\Destinations;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\BackupDestinations\Pages\ListBackupDestinations;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Console → Operations → Backup destinations (P06-10; PLATFORM_CONSOLE.md
 * § 4 "Destinations"), `backups.destinations`: where the nightly and
 * on-demand backups go. **Add** an S3-compatible bucket or a Google Drive
 * Shared Drive folder, **Change** one (credentials are never shown; a blank
 * credential keeps the stored one), **Check** it (writes, reads back and
 * deletes a small file), **Switch off / on**. Changes go through
 * App\Backup\Operations\Destinations, which re-checks and audits.
 */
final class BackupDestinationResource extends Resource
{
    use ActsAsStaff;

    protected static ?string $model = BackupDestination::class;

    protected static ?string $slug = 'backup-destinations';

    protected static ?string $modelLabel = 'backup destination';

    protected static bool $isGloballySearchable = false;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Backup destinations';

    protected static ?int $navigationSort = 22;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('driver')->label('Kind')->formatStateUsing(fn (string $state): string => match ($state) {
                    's3' => 'S3-compatible',
                    'google_drive' => 'Google Drive',
                    default => 'Folder on this server',
                }),
                TextColumn::make('where')->label('Where')->state(fn (BackupDestination $record): string => self::where($record))->wrap(),
                TextColumn::make('applies_to')->label('Takes')->formatStateUsing(fn (string $state): string => match ($state) {
                    'db' => 'Database',
                    'files' => 'Files',
                    default => 'Database and files',
                }),
                IconColumn::make('active')->boolean(),
                TextColumn::make('checked_at')->label('Last check')->dateTime('j M Y H:i', 'Africa/Lagos')->placeholder('Never')
                    ->description(fn (BackupDestination $record): ?string => $record->checked_at === null ? null : ($record->check_problem ?? 'It worked.'))
                    ->color(fn (BackupDestination $record): ?string => $record->check_problem !== null ? 'danger' : null),
            ])
            ->headerActions([
                Action::make('add')
                    ->label('Add destination')
                    ->icon(Heroicon::OutlinedPlus)
                    ->authorize(fn (): bool => self::actor()->can('create', BackupDestination::class))
                    ->modalDescription('Use a different provider or account from the live media bucket, so one compromised account cannot delete both. Credentials are stored encrypted and never shown again.')
                    ->schema(fn (): array => self::fields(null))
                    ->action(fn (array $data): bool => self::attempt(function () use ($data): string {
                        $destination = app(Destinations::class)->create(self::actor(), self::flatten($data, (string) ($data['driver'] ?? '')));

                        return "\"{$destination->name}\" added. Check it before the next backup.";
                    })),
            ])
            ->recordActions([
                Action::make('check')
                    ->icon(Heroicon::OutlinedSignal)
                    ->color('gray')
                    ->authorize(fn (BackupDestination $record): bool => self::actor()->can('update', $record))
                    ->action(function (BackupDestination $record): void {
                        $problem = null;
                        $ok = self::attempt(function () use ($record, &$problem): ?string {
                            $problem = app(Destinations::class)->check(self::actor(), $record);

                            return null;
                        });

                        if ($ok) {
                            $problem === null
                                ? Notification::make()->title('It works: a test file was written, read back and deleted.')->success()->send()
                                : Notification::make()->title('The check failed')->body($problem)->danger()->persistent()->send();
                        }
                    }),
                Action::make('change')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->color('gray')
                    ->authorize(fn (BackupDestination $record): bool => self::actor()->can('update', $record))
                    ->fillForm(fn (BackupDestination $record): array => self::formState($record))
                    ->schema(fn (BackupDestination $record): array => self::fields($record))
                    ->action(fn (BackupDestination $record, array $data): bool => self::attempt(function () use ($record, $data): string {
                        app(Destinations::class)->update(self::actor(), $record, self::flatten($data, $record->driver));

                        return 'Saved. Check it again.';
                    })),
                Action::make('switch')
                    ->label(fn (BackupDestination $record): string => $record->active ? 'Switch off' : 'Switch on')
                    ->icon(fn (BackupDestination $record): Heroicon => $record->active ? Heroicon::OutlinedPause : Heroicon::OutlinedPlay)
                    ->color(fn (BackupDestination $record): string => $record->active ? 'danger' : 'success')
                    ->authorize(fn (BackupDestination $record): bool => self::actor()->can('update', $record))
                    ->requiresConfirmation()
                    ->modalDescription(fn (BackupDestination $record): string => $record->active
                        ? 'No backups go there until it is switched on again. The backups already there stay, and can still be downloaded and restored.'
                        : 'Backups go there again from the next run.')
                    ->action(fn (BackupDestination $record): bool => self::attempt(function () use ($record): string {
                        app(Destinations::class)->switch(self::actor(), $record, ! $record->active);

                        return $record->active ? 'Switched on.' : 'Switched off.';
                    })),
            ])
            ->defaultSort('id');
    }

    /** @return list<Component|Field> */
    private static function fields(?BackupDestination $record): array
    {
        $fields = [
            TextInput::make('name')->required()->maxLength(100),
            $record === null
                ? Select::make('driver')->label('Kind')->options(Destinations::drivers())->required()->live()
                : TextInput::make('driver_label')->label('Kind')->disabled()->dehydrated(false),
            Select::make('applies_to')->label('Takes')->options(['both' => 'Database and files', 'db' => 'Database only', 'files' => 'Files only'])->required()->default('both'),
        ];

        $chosen = $record !== null ? fn (Get $get): string => $record->driver : fn (Get $get): string => (string) $get('driver');
        $keepSecrets = $record !== null;

        foreach (Destinations::FIELDS as $driver => $driverFields) {
            $visible = fn (Get $get): bool => $chosen($get) === $driver;

            foreach ($driverFields as [$field, $label, $secret, $required]) {
                $input = match (true) {
                    $field === 'service_account' => Textarea::make($driver.'.'.$field)->rows(4),
                    $secret => TextInput::make($driver.'.'.$field)->password()->revealable(false),
                    default => TextInput::make($driver.'.'.$field),
                };
                $fields[] = $input
                    ->label($label)
                    ->visible($visible)
                    // Hidden fields (another kind's) are not validated.
                    ->required(self::mustFill($required, $secret, $keepSecrets))
                    ->helperText($secret && $keepSecrets ? 'Leave blank to keep the one stored.' : null);
            }

            if ($driver === 's3') {
                $fields[] = Toggle::make('s3.use_path_style_endpoint')->label('Path-style URLs (some providers need them)')->visible($visible);
            }
        }

        return $fields;
    }

    /** A credential already stored may be left blank on a change. */
    private static function mustFill(bool $required, bool $secret, bool $changing): bool
    {
        return $required && ! ($secret && $changing);
    }

    /**
     * The form keeps each kind's fields apart (s3.bucket, google_drive.…);
     * Destinations takes them flat.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function flatten(array $data, string $driver): array
    {
        $own = is_array($data[$driver] ?? null) ? $data[$driver] : [];

        return array_merge(array_diff_key($data, Destinations::FIELDS), $own);
    }

    /** @return array<string, mixed> */
    private static function formState(BackupDestination $record): array
    {
        $state = ['name' => $record->name, 'applies_to' => $record->applies_to, 'driver_label' => Destinations::drivers()[$record->driver] ?? $record->driver];

        $own = [];

        foreach (Destinations::FIELDS[$record->driver] ?? [] as [$field, , $secret]) {
            $own[$field] = $secret ? null : ($record->config[$field] ?? null);
        }

        if ($record->driver === 's3') {
            $own['use_path_style_endpoint'] = (bool) ($record->config['use_path_style_endpoint'] ?? false);
        }

        $state[$record->driver] = $own;

        return $state;
    }

    private static function where(BackupDestination $record): string
    {
        $config = $record->config;

        return match ($record->driver) {
            's3' => ($config['bucket'] ?? '?').'/'.($config['root'] ?? '').(isset($config['endpoint']) ? ' at '.parse_url((string) $config['endpoint'], PHP_URL_HOST) : ''),
            'google_drive' => 'Shared Drive '.($config['shared_drive_id'] ?? '?').(isset($config['folder_id']) ? ', folder '.$config['folder_id'] : ''),
            default => (string) ($config['root'] ?? ''),
        };
    }

    public static function getPages(): array
    {
        return ['index' => ListBackupDestinations::route('/')];
    }
}
