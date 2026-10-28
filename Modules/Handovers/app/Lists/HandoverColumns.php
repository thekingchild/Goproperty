<?php

declare(strict_types=1);

namespace Modules\Handovers\Lists;

use App\Exports\ExportColumn;
use Modules\Handovers\Models\ManagementHandover;

/**
 * The columns both handover lists export (P02-07), after the other
 * organization's name: the status as the page shows it (an open offer past
 * its 14 days reads "Expired"), the buildings and units, rent collection,
 * notice, who offered and answered and when, the bill change accepted
 * (naira from kobo, before VAT) and the notice dates.
 */
final class HandoverColumns
{
    /** @return list<ExportColumn> */
    public static function common(): array
    {
        return [
            ExportColumn::make('Status', fn (ManagementHandover $handover) => $handover->shownStatus()),
            ExportColumn::make('Kind', fn (ManagementHandover $handover) => $handover->kind),
            ExportColumn::make('Buildings', fn (ManagementHandover $handover) => $handover->buildings->pluck('property_name')->implode(', ')),
            ExportColumn::make('Units', fn (ManagementHandover $handover) => $handover->unitCount()),
            ExportColumn::make('Rent collected by', fn (ManagementHandover $handover) => $handover->rent_collected_by),
            ExportColumn::make('Notice days', fn (ManagementHandover $handover) => $handover->notice_days),
            ExportColumn::make('Offered by', fn (ManagementHandover $handover) => $handover->initiatedBy?->name),
            ExportColumn::make('Offered on', fn (ManagementHandover $handover) => $handover->created_at),
            ExportColumn::make('Expires on', fn (ManagementHandover $handover) => $handover->expires_at),
            ExportColumn::make('Answered by', fn (ManagementHandover $handover) => $handover->respondedBy?->name),
            ExportColumn::make('Answered on', fn (ManagementHandover $handover) => $handover->responded_at ?? $handover->cancelled_at),
            ExportColumn::make('Bill a month before VAT, before', fn (ManagementHandover $handover) => $handover->monthly_before_kobo),
            ExportColumn::make('Bill a month before VAT, after', fn (ManagementHandover $handover) => $handover->monthly_after_kobo),
            ExportColumn::make('Notice given on', fn (ManagementHandover $handover) => $handover->notice_given_at),
            ExportColumn::make('Ends on', fn (ManagementHandover $handover) => $handover->ends_at),
            ExportColumn::make('Ended on', fn (ManagementHandover $handover) => $handover->ended_at),
        ];
    }
}
