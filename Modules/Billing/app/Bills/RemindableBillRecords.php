<?php

declare(strict_types=1);

namespace Modules\Billing\Bills;

use App\Support\Money;
use Modules\Billing\Contracts\RemindableBill;
use Modules\Billing\Contracts\RemindableBills;
use Modules\Billing\Enums\BillStatus;
use Modules\Billing\Enums\BillType;
use Modules\Billing\Models\Bill;

/**
 * The Billing module's answers to Contracts\RemindableBills (the reminder
 * engine, P05-01), read through the organization scope. Soft-deleted bills
 * (an import's rollback) are never found. The unit and building are the
 * bill's parties as issued (BillDetails).
 */
final readonly class RemindableBillRecords implements RemindableBills
{
    public function __construct(private BillDetails $details) {}

    public function dueOn(array $dates, int $afterId = 0, int $limit = 500): array
    {
        if ($dates === []) {
            return [];
        }

        return array_values(Bill::query()
            ->whereIn('status', [BillStatus::Unpaid, BillStatus::PartiallyPaid, BillStatus::Overdue])
            ->whereIn('due_date', array_values(array_unique($dates)))
            ->whereColumn('paid_kobo', '<', 'amount_kobo')
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (Bill $bill): RemindableBill => $this->summary($bill))
            ->all());
    }

    public function find(int $billId): ?RemindableBill
    {
        $bill = Bill::query()->find($billId);

        return $bill instanceof Bill ? $this->summary($bill) : null;
    }

    public function rentFor(int $leaseId, string $periodStart): ?RemindableBill
    {
        $bill = Bill::query()
            ->where('lease_id', $leaseId)
            ->where('type', BillType::Rent)
            ->whereDate('period_start', $periodStart)
            ->first();

        return $bill instanceof Bill ? $this->summary($bill) : null;
    }

    private function summary(Bill $bill): RemindableBill
    {
        $parties = $this->details->of($bill);
        $owed = Money::max(Money::zero(), $bill->amount_kobo->minus($bill->paid_kobo));

        return new RemindableBill(
            id: $bill->id,
            organizationId: $bill->organization_id,
            type: $bill->type->value,
            typeLabel: $bill->type->label(),
            leaseId: $bill->lease_id,
            tenantProfileId: $bill->tenant_profile_id,
            number: $bill->number,
            paymentReference: $bill->payment_reference,
            description: $bill->description,
            amount: $bill->amount_kobo,
            owed: $owed,
            dueDate: $bill->due_date,
            periodStart: $bill->period_start,
            periodEnd: $bill->period_end,
            open: $bill->status->isOpen() && $owed->isPositive(),
            imported: $bill->import_batch_id !== null,
            unit: $parties->unit,
            building: $parties->buildingName,
        );
    }
}
