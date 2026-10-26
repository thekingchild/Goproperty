<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\ImpersonationSessions;

use App\Models\ImpersonationSession;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\ImpersonationSessions\Pages\ListImpersonationSessions;
use App\Platform\Filament\Resources\ImpersonationSessions\Pages\ViewImpersonationSession;
use App\Platform\Filament\Resources\ImpersonationSessions\RelationManagers\TimelineRelationManager;
use App\Platform\Impersonation\ImpersonationEndReason;
use App\Platform\Impersonation\ImpersonationMode;
use App\Platform\Impersonation\ImpersonationRefused;
use App\Platform\Impersonation\Impersonations;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Console → Customers → Impersonation log (PLATFORM_CONSOLE.md § 6): every
 * support session, filterable by staff member, organization, user, mode and
 * date; a session's page shows the reason, the approval and the full
 * timeline of pages and actions. Needs `impersonation.view_log`
 * (ImpersonationSessionPolicy); reading it is itself audited. Active
 * sessions can be ended by a super-admin.
 */
final class ImpersonationSessionResource extends Resource
{
    use ActsAsStaff;

    protected static ?string $model = ImpersonationSession::class;

    protected static ?string $slug = 'impersonation-log';

    protected static ?string $modelLabel = 'support session';

    protected static ?string $pluralModelLabel = 'impersonation log';

    protected static bool $isGloballySearchable = false;

    protected static string|UnitEnum|null $navigationGroup = 'Customers';

    protected static ?string $navigationLabel = 'Impersonation log';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEye;

    /** Active sessions, as a badge. */
    public static function getNavigationBadge(): ?string
    {
        $active = ImpersonationSession::query()->whereNull('ended_at')->where('expires_at', '>', now())->count();

        return $active > 0 ? (string) $active : null;
    }

    public static function status(ImpersonationSession $session): string
    {
        return match (true) {
            $session->isActive() => 'Active',
            $session->ended_reason !== null => $session->ended_reason->label(),
            default => 'Timed out',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['staff:id,name', 'user:id,name,email', 'organization:id,name,slug']))
            ->columns([
                TextColumn::make('started_at')->label('Started')->dateTime()->sortable(),
                TextColumn::make('staff.name')->label('Staff member'),
                TextColumn::make('user.name')
                    ->label('User')
                    ->description(fn (ImpersonationSession $record): ?string => $record->user->email),
                TextColumn::make('organization.name')->label('Organization'),
                TextColumn::make('mode')
                    ->badge()
                    ->formatStateUsing(fn (ImpersonationMode $state): string => $state->label())
                    ->color(fn (ImpersonationMode $state): string => $state === ImpersonationMode::Write ? 'danger' : 'gray'),
                TextColumn::make('reason')->limit(60)->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->state(fn (ImpersonationSession $record): string => self::status($record))
                    ->color(fn (ImpersonationSession $record): string => $record->isActive() ? 'warning' : 'gray'),
                TextColumn::make('ended_at')->label('Ended')->dateTime()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('staff')->label('Staff member')->relationship('staff', 'name')->searchable()->preload(),
                SelectFilter::make('organization')->relationship('organization', 'name')->searchable(),
                SelectFilter::make('user')->relationship('user', 'name')->searchable(),
                SelectFilter::make('mode')->options(collect(ImpersonationMode::cases())->mapWithKeys(fn (ImpersonationMode $mode): array => [$mode->value => $mode->label()])->all()),
                TernaryFilter::make('active')
                    ->label('Active now')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNull('ended_at')->where('expires_at', '>', now()),
                        false: fn (Builder $query) => $query->where(fn (Builder $ended) => $ended->whereNotNull('ended_at')->orWhere('expires_at', '<=', now())),
                    ),
                Filter::make('started')
                    ->schema([
                        DatePicker::make('from')->label('Started from'),
                        DatePicker::make('until')->label('Started until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $from, mixed $date) => $from->whereDate('started_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $until, mixed $date) => $until->whereDate('started_at', '<=', $date))),
            ])
            ->recordActions([ViewAction::make(), self::endAction()])
            ->defaultSort('started_at', 'desc')
            ->paginated([25, 50, 100]);
    }

    /** A super-admin ends someone's active session (ImpersonationSessionPolicy::end). */
    public static function endAction(): Action
    {
        return Action::make('end')
            ->label('End session')
            ->icon('heroicon-o-stop-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(fn (ImpersonationSession $record): string => "Ends {$record->staff->name}'s session as {$record->user->name} at once. Their next request returns them to the console.")
            ->authorize(fn (ImpersonationSession $record): bool => self::actor()->can('end', $record))
            ->action(function (ImpersonationSession $record): void {
                try {
                    app(Impersonations::class)->endByStaff(self::actor(), $record);
                    Notification::make()->title('Session ended.')->success()->send();
                } catch (ImpersonationRefused $refused) {
                    Notification::make()->title($refused->getMessage())->danger()->send();
                }
            });
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Session')
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    TextEntry::make('staff.name')->label('Staff member'),
                    TextEntry::make('user.name')->label('User')->helperText(fn (ImpersonationSession $record): ?string => $record->user->email),
                    TextEntry::make('organization.name')->label('Organization'),
                    TextEntry::make('mode')->badge()->formatStateUsing(fn (ImpersonationMode $state): string => $state->label()),
                    TextEntry::make('reason')->columnSpan(2),
                    TextEntry::make('started_at')->label('Started')->dateTime(),
                    TextEntry::make('handed_off_at')->label('Opened in the customer app')->dateTime()->placeholder('Never opened'),
                    TextEntry::make('expires_at')->label('Limit')->dateTime(),
                    TextEntry::make('status')->state(fn (ImpersonationSession $record): string => self::status($record))->badge(),
                    TextEntry::make('ended_at')->label('Ended')->dateTime()->placeholder('—'),
                    TextEntry::make('ended_reason')
                        ->label('How it ended')
                        ->formatStateUsing(fn (ImpersonationEndReason $state): string => $state->label())
                        ->placeholder('—'),
                    TextEntry::make('endedBy.name')->label('Ended by')->placeholder('—'),
                    TextEntry::make('ip')->label('Started from IP')->placeholder('—'),
                    TextEntry::make('user_agent')->label('Browser')->placeholder('—')->columnSpan(2),
                ]),
            Section::make('Approval (two-person rule)')
                ->columnSpanFull()
                ->columns(3)
                ->visible(fn (ImpersonationSession $record): bool => $record->approval_request_id !== null)
                ->schema([
                    TextEntry::make('approvalRequest.requestedBy.name')->label('Requested by'),
                    TextEntry::make('approvalRequest.approvedBy.name')->label('Approved by')->placeholder('—'),
                    TextEntry::make('approvalRequest.decided_at')->label('Approved at')->dateTime()->placeholder('—'),
                    TextEntry::make('approvalRequest.decision_note')->label('Approver\'s note')->placeholder('—')->columnSpanFull(),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [TimelineRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImpersonationSessions::route('/'),
            'view' => ViewImpersonationSession::route('/{record}'),
        ];
    }
}
