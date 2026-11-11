<?php

declare(strict_types=1);

namespace Modules\Billing\Bills;

use App\Documents\BrandingSnapshots;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Billing\Contracts\BillBalance;
use Modules\Billing\Contracts\BillLedger;
use Modules\Billing\Contracts\LateFeeBills;
use Modules\Billing\Enums\BillStatus;
use Modules\Billing\Enums\BillType;
use Modules\Billing\Models\Bill;

/**
 * The Billing module's answer to Contracts\LateFeeBills (PL-02): the rent
 * bills due for a fee, and the fee bill itself, issued through BillIssuer
 * for the rent bill's parties.
 */
final readonly class LateFeeBillRecords implements LateFeeBills
{
    public function __construct(
        private BillIssuer $issuer,
        private BrandingSnapshots $snapshots,
        private OrganizationContext $context,
        private BillLedger $ledger,
        private BillDetails $details,
    ) {}

    public function rentBillsDue(CarbonImmutable $dueBy, int $afterId, int $limit): array
    {
        $ids = Bill::query()
            ->where('type', BillType::Rent)
            ->whereIn('status', [BillStatus::Unpaid, BillStatus::PartiallyPaid, BillStatus::Overdue])
            ->whereNull('import_batch_id')
            ->where('due_date', '<=', $dueBy->toDateString())
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->pluck('id');

        $balances = [];

        foreach ($ids as $id) {
            $balance = $this->ledger->balance((int) $id);

            if ($balance !== null) {
                $balances[] = $balance;
            }
        }

        return $balances;
    }

    public function issue(int $rentBillId, Money $fee, string $note): BillBalance
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Issue a late fee inside a transaction.');
        }

        $rent = Bill::query()->whereKey($rentBillId)->first();

        if (! $rent instanceof Bill || $rent->type !== BillType::Rent) {
            throw new LogicException("Bill {$rentBillId} is not one of the organization's rent bills.");
        }

        $parties = $this->details->of($rent);
        $issued = $this->issuer->issue(new BillDraft(
            type: BillType::LateFee,
            billableType: $rent->billable_type,
            billableId: $rent->billable_id,
            tenantProfileId: $rent->tenant_profile_id,
            description: __('Late fee on :number', ['number' => $rent->number]),
            dueDate: BillDates::today(),
            lines: [new BillLine(
                label: __('Late fee'),
                amount: $fee,
                note: $note,
                meta: ['rent_bill_id' => $rent->id, 'rent_bill_number' => $rent->number],
            )],
            leaseId: $rent->lease_id,
            propertyId: $rent->property_id,
            unitId: $rent->unit_id,
            leaseNumber: $parties->leaseNumber,
            cycle: $parties->cycle,
        ), $this->snapshots->take($this->context->get()));

        return $this->ledger->balance($issued->id) ?? throw new LogicException('The late fee was not saved.');
    }
}
