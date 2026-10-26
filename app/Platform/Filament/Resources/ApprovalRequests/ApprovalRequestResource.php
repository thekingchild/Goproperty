<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\ApprovalRequests;

use App\Models\ApprovalRequest;
use App\Platform\Approvals\ApprovalAction;
use App\Platform\Approvals\ApprovalService;
use App\Platform\Approvals\ApprovalStatus;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\ApprovalRequests\Pages\ListApprovalRequests;
use App\Platform\Staff\StaffPermission;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Console → Staff → Approvals (PLATFORM_CONSOLE.md § 1): two-person
 * requests. Approve and Reject are offered only to someone other than the
 * requester who holds the action's permission (ApprovalRequestPolicy →
 * ApprovalService::refusalFor), and the service checks again.
 */
final class ApprovalRequestResource extends Resource
{
    use ActsAsStaff;

    protected static ?string $model = ApprovalRequest::class;

    protected static ?string $slug = 'approvals';

    protected static ?string $modelLabel = 'approval request';

    protected static bool $isGloballySearchable = false;

    protected static string|UnitEnum|null $navigationGroup = 'Staff';

    protected static ?string $navigationLabel = 'Approvals';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    /** Staff who may not see every request see the ones they made or may decide. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['requestedBy', 'approvedBy', 'rejectedBy']);
        $actor = self::actor();

        if ($actor->holdsStaffPermission(StaffPermission::ApprovalsView)) {
            return $query;
        }

        $actions = array_values(array_map(
            fn (ApprovalAction $action): string => $action->value,
            array_filter(ApprovalAction::cases(), fn (ApprovalAction $action): bool => $actor->holdsStaffPermission($action->approverPermission())),
        ));

        return $query->where(fn (Builder $where) => $where->whereIn('action', $actions)->orWhere('requested_by_staff_id', $actor->id));
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = self::getEloquentQuery()->where('status', ApprovalStatus::Pending)->where('expires_at', '>', now())->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function statusColor(ApprovalStatus $status): string
    {
        return match ($status) {
            ApprovalStatus::Pending => 'warning',
            ApprovalStatus::Approved => 'success',
            ApprovalStatus::Rejected => 'danger',
            ApprovalStatus::Expired => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Requested')->since()->sortable(),
                TextColumn::make('action')->badge()->formatStateUsing(fn (ApprovalAction $state): string => $state->label()),
                TextColumn::make('summary')->wrap()->searchable(),
                TextColumn::make('reason')->wrap()->placeholder('—'),
                TextColumn::make('requestedBy.name')->label('Requested by'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ApprovalStatus $state): string => ucfirst($state->value))
                    ->color(fn (ApprovalStatus $state): string => self::statusColor($state)),
                TextColumn::make('decided_by')
                    ->label('Decided by')
                    ->state(fn (ApprovalRequest $record): ?string => $record->approvedBy->name ?? $record->rejectedBy?->name)
                    ->placeholder('—'),
                TextColumn::make('expires_at')->label('Expires')->dateTime()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(ApprovalStatus::cases())->mapWithKeys(fn (ApprovalStatus $status): array => [$status->value => ucfirst($status->value)])->all())
                    ->default(ApprovalStatus::Pending->value),
                SelectFilter::make('action')
                    ->options(collect(ApprovalAction::cases())->mapWithKeys(fn (ApprovalAction $action): array => [$action->value => $action->label()])->all()),
            ])
            ->recordActions([
                Action::make('approve')
                    ->color('success')
                    ->icon('heroicon-o-check')
                    ->requiresConfirmation()
                    ->modalDescription(fn (ApprovalRequest $record): string => $record->summary)
                    ->schema([Textarea::make('note')->maxLength(500)])
                    ->authorize(fn (ApprovalRequest $record): bool => self::actor()->can('approve', $record))
                    ->action(fn (ApprovalRequest $record, array $data): bool => self::attempt(function () use ($record, $data): string {
                        app(ApprovalService::class)->approve($record, self::actor(), isset($data['note']) ? (string) $data['note'] : null);

                        return 'Approved.';
                    })),
                Action::make('reject')
                    ->color('danger')
                    ->icon('heroicon-o-x-mark')
                    ->requiresConfirmation()
                    ->modalDescription(fn (ApprovalRequest $record): string => $record->summary)
                    ->schema([Textarea::make('note')->maxLength(500)])
                    ->authorize(fn (ApprovalRequest $record): bool => self::actor()->can('reject', $record))
                    ->action(fn (ApprovalRequest $record, array $data): bool => self::attempt(function () use ($record, $data): string {
                        app(ApprovalService::class)->reject($record, self::actor(), isset($data['note']) ? (string) $data['note'] : null);

                        return 'Rejected.';
                    })),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListApprovalRequests::route('/')];
    }
}
