<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Lists;

use App\Exports\ExportColumn;
use Modules\SaasBilling\Enums\BillingTransferStatus;
use Modules\SaasBilling\Models\BillingTransferRequest;

/**
 * The columns both billing transfer lists export (P02-07), after the
 * other organization's name: the status as the page shows it (an open
 * offer past its 14 days reads "Expired"), who offered and answered, when,
 * and the monthly amount accepted (naira from kobo, before VAT).
 */
final class BillingTransferColumns
{
    /** @return list<ExportColumn> */
    public static function common(): array
    {
        return [
            ExportColumn::make('Status', fn (BillingTransferRequest $transfer) => $transfer->status === BillingTransferStatus::Pending && $transfer->isPastExpiry()
                ? BillingTransferStatus::Expired
                : $transfer->status),
            ExportColumn::make('Offered by', fn (BillingTransferRequest $transfer) => $transfer->initiatedBy?->name),
            ExportColumn::make('Offered on', fn (BillingTransferRequest $transfer) => $transfer->created_at),
            ExportColumn::make('Expires on', fn (BillingTransferRequest $transfer) => $transfer->expires_at),
            ExportColumn::make('Answered by', fn (BillingTransferRequest $transfer) => $transfer->respondedBy?->name),
            ExportColumn::make('Answered on', fn (BillingTransferRequest $transfer) => $transfer->responded_at ?? $transfer->cancelled_at),
            ExportColumn::make('Plan', fn (BillingTransferRequest $transfer) => $transfer->plan_key),
            ExportColumn::make('Units counted', fn (BillingTransferRequest $transfer) => $transfer->billable_units),
            ExportColumn::make('Amount a month before VAT', fn (BillingTransferRequest $transfer) => $transfer->monthly_amount_kobo),
        ];
    }
}
