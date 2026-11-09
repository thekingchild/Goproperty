<?php

declare(strict_types=1);

namespace Modules\Billing\Bills;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Modules\Billing\Contracts\BilledTotal;
use Modules\Billing\Contracts\BillPlace;
use Modules\Billing\Contracts\BillReports;
use Modules\Billing\Contracts\ReportBill;
use Modules\Billing\Enums\BillStatus;
use Modules\Billing\Models\Bill;

/**
 * The Billing module's answers to Contracts\BillReports (the customer
 * dashboards, P08-01), through the organization scope, a chunk at a time.
 * Reads only. billedTotals() and placesOf() read plain rows, never models:
 * a year of a large agency's bills is ~12,000 of them, which as models
 * took seconds (P09-03, docs/runbooks/load-test.md).
 */
final readonly class BillReportRecords implements BillReports
{
    private const array COLUMNS = ['id', 'organization_id', 'type', 'tenant_profile_id', 'lease_id', 'property_id', 'unit_id', 'number', 'amount_kobo', 'paid_kobo', 'status', 'issued_at', 'due_date', 'details'];

    public function __construct(private BillDetails $details) {}

    public function issuedBetween(CarbonImmutable $from, CarbonImmutable $to, ?array $propertyIds = null): array
    {
        $timezone = (string) config('app.timezone');

        return $this->read(self::in($propertyIds)
            ->where('issued_at', '>=', $from->setTimezone($timezone))
            ->where('issued_at', '<', $to->setTimezone($timezone))
            ->orderBy('issued_at')
            ->orderBy('id'));
    }

    public function open(?array $propertyIds = null): array
    {
        return $this->read(self::in($propertyIds)
            ->whereIn('status', [BillStatus::Unpaid, BillStatus::PartiallyPaid, BillStatus::Overdue])
            ->orderBy('due_date')
            ->orderBy('id'));
    }

    public function findMany(array $billIds): array
    {
        $found = [];

        foreach (array_chunk(array_values(array_unique($billIds)), 1000) as $chunk) {
            foreach (Bill::query()->whereKey($chunk)->get(self::COLUMNS) as $bill) {
                $found[$bill->id] = $this->summary($bill);
            }
        }

        return $found;
    }

    public function billedTotals(CarbonImmutable $from, CarbonImmutable $to, ?array $propertyIds, array $periods): array
    {
        $stored = (string) config('app.timezone');
        // The period each bill falls in, by comparing `issued_at` as stored
        // (the app's timezone) with each later period's first moment
        // written the same way: exact whatever the app's timezone, with no
        // date object per bill.
        $keys = array_keys($periods);
        $edges = [];

        foreach (array_slice($periods, 1, null, true) as $start) {
            $edges[] = $start->setTimezone($stored)->format('Y-m-d H:i:s');
        }

        $rows = self::in($propertyIds)
            ->where('issued_at', '>=', $from->setTimezone($stored))
            ->where('issued_at', '<', $to->setTimezone($stored))
            ->where('status', '!=', BillStatus::Void)
            ->orderBy('issued_at')
            ->toBase()
            ->get(['property_id', 'type', 'amount_kobo', 'issued_at']);

        /** @var array<string, array{?int, string, string, int, int}> $totals */
        $totals = [];
        $month = 0;
        $last = count($edges);

        foreach ($rows as $row) {
            $at = (string) $row->issued_at;

            while ($month < $last && $at >= $edges[$month]) {
                $month++;
            }

            $propertyId = $row->property_id === null ? null : (int) $row->property_id;
            $key = $propertyId.'|'.$row->type.'|'.$keys[$month];
            $totals[$key] ??= [$propertyId, (string) $row->type, $keys[$month], 0, 0];
            $totals[$key][3] += (int) $row->amount_kobo;
            $totals[$key][4]++;
        }

        return array_values(array_map(
            static fn (array $total): BilledTotal => new BilledTotal($total[0], $total[1], $total[2], Money::kobo($total[3]), $total[4]),
            $totals,
        ));
    }

    public function placesOf(array $billIds): array
    {
        $places = [];

        foreach (array_chunk(array_values(array_unique($billIds)), 1000) as $chunk) {
            foreach (Bill::query()->whereKey($chunk)->toBase()->get(['id', 'property_id', 'type']) as $row) {
                $places[(int) $row->id] = new BillPlace((int) $row->id, $row->property_id === null ? null : (int) $row->property_id, (string) $row->type);
            }
        }

        return $places;
    }

    /**
     * @param  list<int>|null  $propertyIds
     * @return Builder<Bill>
     */
    private static function in(?array $propertyIds): Builder
    {
        return Bill::query()->when($propertyIds !== null, static fn (Builder $query) => $query->whereIn('property_id', $propertyIds === [] ? [0] : $propertyIds));
    }

    /**
     * @param  Builder<Bill>  $query
     * @return list<ReportBill>
     */
    private function read(Builder $query): array
    {
        $bills = [];

        foreach ($query->select(self::COLUMNS)->lazy(500) as $bill) {
            $bills[] = $this->summary($bill);
        }

        return $bills;
    }

    private function summary(Bill $bill): ReportBill
    {
        $parties = $this->details->of($bill);

        return new ReportBill(
            id: $bill->id,
            propertyId: $bill->property_id,
            unitId: $bill->unit_id,
            tenantProfileId: $bill->tenant_profile_id,
            number: $bill->number,
            type: $bill->type->value,
            typeLabel: $bill->type->label(),
            status: $bill->status->value,
            statusLabel: $bill->status->label(),
            amount: $bill->amount_kobo,
            paid: $bill->paid_kobo,
            outstanding: $bill->outstanding(),
            issuedAt: $bill->issued_at,
            dueDate: $bill->due_date,
            tenantName: $parties->tenantName,
            unit: $parties->unit,
        );
    }
}
