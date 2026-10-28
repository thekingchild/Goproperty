<?php

declare(strict_types=1);

namespace Modules\Billing\Bills;

use App\Documents\Parts\DocumentPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Modules\Billing\Contracts\OwnerBill;
use Modules\Billing\Contracts\OwnerBills;
use Modules\Billing\Models\Bill;
use Modules\Billing\Models\BillItem;

/**
 * The Billing module's answers to Contracts\OwnerBills (the owner portal,
 * P03-15), read through the organization scope and only among the given
 * buildings' bills, so a guessed id of any other bill is not found. The
 * tenant is named as the invoice was issued (`bills.details`), never with
 * their contact details. Reads only.
 */
final readonly class OwnerBillRecords implements OwnerBills
{
    public function __construct(private BillDetails $details) {}

    public function forBuildings(array $propertyIds, ?int $leaseId = null, int $limit = 50): array
    {
        if ($propertyIds === []) {
            return [];
        }

        return array_values(self::of($propertyIds, $leaseId)
            ->orderByDesc('due_date')
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (Bill $bill): OwnerBill => $this->summary($bill))
            ->all());
    }

    public function countFor(array $propertyIds, ?int $leaseId = null): int
    {
        return $propertyIds === [] ? 0 : self::of($propertyIds, $leaseId)->count();
    }

    public function find(int $billId, array $propertyIds): ?OwnerBill
    {
        if ($propertyIds === []) {
            return null;
        }

        $bill = self::of($propertyIds)->whereKey($billId)->first();

        if (! $bill instanceof Bill) {
            return null;
        }

        return $this->summary($bill, array_values(BillItem::query()->where('bill_id', $bill->id)->orderBy('id')->get()
            ->map(static fn (BillItem $item): array => [
                'label' => $item->label,
                'period' => self::period($item->period_start, $item->period_end),
                'amount' => $item->amount_kobo->format(),
            ])->all()));
    }

    public function issuedBetween(array $propertyIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($propertyIds === []) {
            return [];
        }

        return array_values(self::of($propertyIds)
            ->where('issued_at', '>=', $from)
            ->where('issued_at', '<', $to)
            ->orderBy('issued_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Bill $bill): OwnerBill => $this->summary($bill))
            ->all());
    }

    public function findMany(array $billIds): array
    {
        $found = [];

        foreach (array_chunk(array_values(array_unique($billIds)), 1000) as $chunk) {
            foreach (Bill::query()->whereKey($chunk)->get() as $bill) {
                $found[$bill->id] = $this->summary($bill);
            }
        }

        return $found;
    }

    /**
     * @param  list<int>  $propertyIds
     * @return Builder<Bill>
     */
    private static function of(array $propertyIds, ?int $leaseId = null): Builder
    {
        return Bill::query()->whereIn('property_id', $propertyIds)
            ->when($leaseId !== null, static fn (Builder $query) => $query->where('lease_id', $leaseId));
    }

    /** @param  list<array{label: string, period: ?string, amount: string}>  $lines */
    private function summary(Bill $bill, array $lines = []): OwnerBill
    {
        $parties = $this->details->of($bill);

        return new OwnerBill(
            id: $bill->id,
            organizationId: $bill->organization_id,
            propertyId: $bill->property_id,
            unitId: $bill->unit_id,
            leaseId: $bill->lease_id,
            number: $bill->number,
            typeLabel: $bill->type->label(),
            description: $bill->description,
            status: $bill->status->value,
            statusLabel: $bill->status->label(),
            statusColor: $bill->status->color(),
            amount: $bill->amount_kobo,
            paid: $bill->paid_kobo,
            outstanding: $bill->outstanding(),
            issuedAt: $bill->issued_at,
            dueDate: $bill->due_date,
            periodStart: $bill->period_start,
            periodEnd: $bill->period_end,
            tenantName: $parties->tenantName !== '' ? $parties->tenantName : null,
            unit: $parties->unit,
            lines: $lines,
            type: $bill->type->value,
        );
    }

    private static function period(?CarbonImmutable $start, ?CarbonImmutable $end): ?string
    {
        return $start !== null && $end !== null ? (new DocumentPeriod($start, $end))->label() : null;
    }
}
