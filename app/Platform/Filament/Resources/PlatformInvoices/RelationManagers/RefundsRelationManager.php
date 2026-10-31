<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\PlatformInvoices\RelationManagers;

use App\Models\PlatformStaff;
use App\Platform\Billing\PlatformRefundRecord;
use App\Platform\Filament\Resources\PlatformPayments\RelationManagers\RefundsRelationManager as PaymentRefunds;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** The refunds of an invoice's payments (P06-07b), as on the payment's page. */
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
        return PaymentRefunds::refundTable($table);
    }
}
