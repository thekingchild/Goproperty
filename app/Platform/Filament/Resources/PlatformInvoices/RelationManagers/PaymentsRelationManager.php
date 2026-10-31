<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\PlatformInvoices\RelationManagers;

use App\Models\PlatformStaff;
use App\Platform\Billing\PlatformPaymentRecord;
use App\Platform\Filament\Resources\PlatformPayments\PlatformPaymentResource;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An invoice's payments (P06-07b): every Paystack attempt and bank-transfer
 * proof, newest first, each opening its payment's page for staff who see
 * payments. Read-only.
 */
final class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Payments';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $staff = Filament::auth()->user();

        return $staff instanceof PlatformStaff && $staff->can('viewAny', PlatformPaymentRecord::class);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->select('platform_payments.*')->addSelect(PlatformPaymentRecord::detailColumns()))
            ->columns([
                TextColumn::make('created_at')->label('Started')->dateTime(),
                TextColumn::make('method')->formatStateUsing(fn (string $state): string => PlatformPaymentRecord::METHODS[$state] ?? $state)
                    ->description(fn (PlatformPaymentRecord $record): ?string => $record->paystack_reference),
                TextColumn::make('amount_kobo')->label('Amount')->formatStateUsing(fn (PlatformPaymentRecord $record): string => PlatformPaymentResource::money($record->paidKobo())),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state): string => PlatformPaymentRecord::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => PlatformPaymentResource::statusColor($state)),
                TextColumn::make('refunded_kobo')->label('Refunded')->formatStateUsing(fn (?int $state): string => PlatformPaymentResource::money((int) $state)),
            ])
            ->recordUrl(fn (PlatformPaymentRecord $record): string => PlatformPaymentResource::getUrl('view', ['record' => $record->id]))
            ->defaultSort('id', 'desc')
            ->paginated([10, 25]);
    }
}
