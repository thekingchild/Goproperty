<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\PlatformPayments\RelationManagers;

use App\Models\PlatformStaff;
use App\Platform\Billing\PlatformRefundRecord;
use App\Platform\Billing\Refunds;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\SaasBilling\Contracts\RefundRefused;

/**
 * A payment's refunds (P06-07b), newest first: amount, status (red when
 * Paystack failed it), reason, who asked, who ran or approved it, Paystack's
 * refund id or GoProperty's transfer reference, and whether the invoice was
 * opened again. **Check with Paystack** asks about one still processing.
 * Read-only otherwise: PlatformRefundRecordPolicy.
 */
final class RefundsRelationManager extends RelationManager
{
    protected static string $relationship = 'refunds';

    protected static ?string $title = 'Refunds';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $staff = Filament::auth()->user();

        return $staff instanceof PlatformStaff && $staff->can('viewAny', PlatformRefundRecord::class);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return self::refundTable($table);
    }

    public static function refundTable(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['requester:id,name', 'executor:id,name']))
            ->columns([
                TextColumn::make('created_at')->label('Asked')->dateTime(),
                TextColumn::make('amount_kobo')->label('Amount')->formatStateUsing(fn (int $state): string => Money::kobo($state)->format()),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state): string => PlatformRefundRecord::STATUSES[$state] ?? $state)
                    ->color(fn (PlatformRefundRecord $record): string => $record->statusColor())
                    ->description(fn (PlatformRefundRecord $record): ?string => $record->failure_reason),
                TextColumn::make('reason')->wrap()->limit(120),
                TextColumn::make('requester.name')->label('Asked by'),
                TextColumn::make('executor.name')->label('Run by')->placeholder('—')
                    ->description(fn (PlatformRefundRecord $record): ?string => $record->approval_request_id !== null ? 'approved (two-person rule)' : null),
                TextColumn::make('reference')->label('Reference')
                    ->state(fn (PlatformRefundRecord $record): ?string => $record->paystack_refund_id !== null ? 'Paystack refund '.$record->paystack_refund_id : $record->bank_reference)
                    ->placeholder('—'),
                IconColumn::make('invoice_reopened')->label('Invoice open again')->boolean(),
            ])
            ->recordActions([
                Action::make('check')
                    ->label('Check with Paystack')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->authorize(function (PlatformRefundRecord $record): bool {
                        $staff = Filament::auth()->user();

                        return $staff instanceof PlatformStaff && $staff->can('check', $record);
                    })
                    ->action(function (PlatformRefundRecord $record): void {
                        $staff = Filament::auth()->user();

                        if (! $staff instanceof PlatformStaff) {
                            throw new AuthorizationException;
                        }

                        try {
                            Notification::make()->title(app(Refunds::class)->check($staff, $record))->success()->send();
                        } catch (RefundRefused $refused) {
                            Notification::make()->title($refused->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([10, 25]);
    }
}
