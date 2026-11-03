<?php

declare(strict_types=1);

namespace Modules\Reports\Lists;

use App\Exports\ExportCell;
use App\Exports\ExportColumn;
use App\Models\User;
use Modules\Reports\Reports\RenewalLine;

/** Upcoming renewals (P08-01): active leases ending in the next 30, 60 or 90 days, soonest first. */
final class RenewalsList extends ReportList
{
    public function key(): string
    {
        return 'report_renewals';
    }

    public function title(): string
    {
        return 'Upcoming renewals';
    }

    public function columns(User $user): array
    {
        return [
            ExportColumn::make('Tenant', static fn (RenewalLine $row): string => $row->tenantName),
            ExportColumn::make('Building', static fn (RenewalLine $row): string => $row->buildingName),
            ExportColumn::make('Unit', static fn (RenewalLine $row): string => $row->unitLabel),
            ExportColumn::make('Lease', static fn (RenewalLine $row): ?string => $row->contractNumber),
            ExportColumn::make('Ends on', static fn (RenewalLine $row) => ExportCell::date($row->endDate)),
            ExportColumn::make('Days left', static fn (RenewalLine $row): int => $row->daysLeft),
            ExportColumn::make('Rent', static fn (RenewalLine $row) => $row->rent),
            ExportColumn::make('Billing cycle', static fn (RenewalLine $row): string => $row->cycleLabel),
            ExportColumn::make('Renewal', static fn (RenewalLine $row): string => $row->renewalLabel),
        ];
    }

    /** @return list<RenewalLine> */
    public function rows(User $user, array $filters): array
    {
        [$scope, $cleaned] = $this->scoped($user, $filters);

        return $this->reports->renewals($scope, $cleaned)->lines;
    }
}
