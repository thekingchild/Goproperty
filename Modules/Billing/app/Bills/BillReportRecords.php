<?php

declare(strict_types=1);

namespace Modules\Billing\Bills;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Modules\Billing\Contracts\BillReports;
use Modules\Billing\Contracts\ReportBill;
use Modules\Billing\Enums\BillStatus;
use Modules\Billing\Models\Bill;

/**
 * The Billing module's answers to Contracts\BillReports (the customer
 * dashboards, P08-01), through the organization scope, a chunk at a time.
 * Reads only.
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
