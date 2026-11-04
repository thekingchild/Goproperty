<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\DataRequests;

use App\Models\DataRequest;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Concerns\BadgeBeforeMigrations;
use App\Platform\Filament\Resources\DataRequests\Pages\ListDataRequests;
use App\Privacy\DataRequestKind;
use App\Privacy\DataRequests;
use App\Privacy\DataRequestScope;
use App\Privacy\DataRequestStatus;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;
use UnitEnum;

/**
 * Console → Compliance → Data requests (P09-02; PLATFORM_CONSOLE.md § 11;
 * NDPA 2023 ss. 34–36): exports of one tenant's or a whole organization's
 * data, and erasures (a tenant anonymised, a cancelled organization
 * purged), with their state. Exports are queued at once and downloaded
 * through a 15-minute signed link (audited); erasures wait for a second
 * staff member's approval in Staff → Approvals (`data.purge`), then for the
 * retention period, and the scheduler carries them out. DataRequestPolicy.
 */
final class DataRequestResource extends Resource
{
    use ActsAsStaff, BadgeBeforeMigrations;

    protected static ?string $model = DataRequest::class;

    protected static ?string $slug = 'data-requests';

    protected static ?string $modelLabel = 'data request';

    protected static ?string $pluralModelLabel = 'Data requests';

    protected static bool $isGloballySearchable = false;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'Data requests';

    protected static ?int $navigationSort = 30;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['organization', 'requestedByStaff', 'requestedByUser']);
    }

    public static function getNavigationBadge(): ?string
    {
        return self::countBadge(fn (): int => DataRequest::query()->whereIn('status', [DataRequestStatus::AwaitingApproval, DataRequestStatus::Queued, DataRequestStatus::Running])->count());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('created_at')->label('Asked')->dateTime('j M Y H:i', 'Africa/Lagos')->sortable(),
                TextColumn::make('kind')->badge()->formatStateUsing(fn (DataRequestKind $state): string => $state->label())
                    ->color(fn (DataRequestKind $state): string => $state === DataRequestKind::Erasure ? 'danger' : 'info'),
                TextColumn::make('about')->label('About')
                    ->state(fn (DataRequest $record): string => ($record->organization->name ?? 'Organization').' · #'.$record->organization_id)
                    ->description(fn (DataRequest $record): string => $record->scope === DataRequestScope::Tenant ? 'Tenant #'.$record->tenant_profile_id : 'Whole organization')
                    ->wrap(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (DataRequestStatus $state): string => $state->label())
                    ->color(fn (DataRequestStatus $state): string => $state->color())
                    ->description(fn (DataRequest $record): ?string => $record->failure),
                TextColumn::make('due_at')->label('Erases from')->dateTime('j M Y H:i', 'Africa/Lagos')->placeholder('—'),
                TextColumn::make('requester')->label('Asked by')
                    ->state(fn (DataRequest $record): string => $record->requestedByStaff !== null ? $record->requestedByStaff->name.' (staff)' : ($record->requestedByUser->name ?? '—')),
                TextColumn::make('reason')->label('Reference and why')->wrap()->limit(120),
                TextColumn::make('file_size')->label('File')
                    ->state(fn (DataRequest $record): ?string => $record->file_size !== null ? Number::fileSize($record->file_size).($record->file_deleted_at !== null ? ' (deleted)' : '') : null)
                    ->description(fn (DataRequest $record): ?string => $record->downloads > 0 ? "Downloaded {$record->downloads}×" : null)
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('kind')->options(collect(DataRequestKind::cases())->mapWithKeys(fn (DataRequestKind $kind): array => [$kind->value => $kind->label()])->all()),
                SelectFilter::make('status')->options(collect(DataRequestStatus::cases())->mapWithKeys(fn (DataRequestStatus $status): array => [$status->value => $status->label()])->all()),
            ])
            ->recordActions([
                Action::make('download')
                    ->label('Download')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->authorize(fn (DataRequest $record): bool => self::actor()->can('download', $record))
                    ->action(fn (DataRequest $record) => redirect()->away(app(DataRequests::class)->downloadUrl($record, console: true))),
                Action::make('cancel')
                    ->label('Withdraw')
                    ->color('danger')
                    ->icon(Heroicon::OutlinedXMark)
                    ->authorize(fn (DataRequest $record): bool => self::actor()->can('cancel', $record))
                    ->requiresConfirmation()
                    ->modalDescription('The erasure will not be carried out. A pending approval request stays in Staff → Approvals until it expires or is rejected.')
                    ->action(fn (DataRequest $record): bool => self::attempt(function () use ($record): string {
                        app(DataRequests::class)->cancel($record, self::actor());

                        return 'Withdrawn.';
                    })),
            ])
            ->poll('15s')
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListDataRequests::route('/')];
    }
}
