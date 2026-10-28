<?php

declare(strict_types=1);

namespace Modules\Billing\Bills;

use App\Documents\BrandingSnapshots;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Billing\Contracts\BillBalance;
use Modules\Billing\Contracts\BillLedger;
use Modules\Billing\Contracts\OpeningBalanceBill;
use Modules\Billing\Contracts\OpeningBalanceBills;
use Modules\Billing\Enums\BillableType;
use Modules\Billing\Enums\BillStatus;
use Modules\Billing\Enums\BillType;
use Modules\Billing\Models\Bill;

/**
 * The Billing module's answer to Contracts\OpeningBalanceBills (P03-13b):
 * opening balances issued through BillIssuer, taken back and brought back
 * for an import's rollback and re-import. A bill for the lease when the
 * import names one (`billable` = the lease), else for the unit.
 */
final readonly class OpeningBalanceBillRecords implements OpeningBalanceBills
{
    public function __construct(
        private BillIssuer $issuer,
        private BrandingSnapshots $snapshots,
        private OrganizationContext $context,
        private BillLedger $ledger,
    ) {}

    public function issue(OpeningBalanceBill $bill): BillBalance
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Issue an opening balance inside a transaction.');
        }

        $asOf = $bill->asOf->format('d/m/Y');
        $issued = $this->issuer->issue(new BillDraft(
            type: BillType::OpeningBalance,
            billableType: $bill->leaseId !== null ? BillableType::Lease : BillableType::Unit,
            billableId: $bill->leaseId ?? $bill->unitId,
            tenantProfileId: $bill->tenantProfileId,
            description: __('Opening balance as at :date', ['date' => $asOf]),
            // Due on the as-of date: owed before the import, so overdue at once (arrears).
            dueDate: $bill->asOf,
            lines: [new BillLine(
                label: __('Opening balance'),
                amount: $bill->amount,
                note: $bill->description ?? __('Owed on :date, brought over from earlier records', ['date' => $asOf]),
                meta: ['as_of' => $bill->asOf->toDateString()],
            )],
            leaseId: $bill->leaseId,
            propertyId: $bill->propertyId,
            unitId: $bill->unitId,
            leaseNumber: $bill->leaseNumber,
            cycle: $bill->cycle,
            importBatchId: $bill->importBatchId,
        ), $this->snapshots->take($this->context->get()));

        return $this->ledger->balance($issued->id) ?? throw new LogicException('The opening balance was not saved.');
    }

    public function withdraw(int $billId): bool
    {
        return DB::transaction(function () use ($billId): bool {
            $bill = Bill::query()->withTrashed()->whereKey($billId)->lockForUpdate()->first();

            if (! $bill instanceof Bill || $bill->type !== BillType::OpeningBalance) {
                return false;
            }

            if ($bill->trashed()) {
                return true;
            }

            if ($bill->status === BillStatus::Void || $bill->paid_kobo->isPositive()) {
                return false;
            }

            $bill->delete();

            activity(BillIssuer::LOG)
                ->event('bill.import_rolled_back')
                ->performedOn($bill)
                ->withProperties(['number' => $bill->number, 'amount_kobo' => $bill->amount_kobo->kobo, 'import_batch_id' => $bill->import_batch_id])
                ->log('Opening balance removed with its import');

            return true;
        }, 3);
    }

    public function restore(int $billId, int $importBatchId): ?BillBalance
    {
        return DB::transaction(function () use ($billId, $importBatchId): ?BillBalance {
            $bill = Bill::query()->withTrashed()->whereKey($billId)->lockForUpdate()->first();

            if (! $bill instanceof Bill || $bill->type !== BillType::OpeningBalance) {
                return null;
            }

            if ($bill->trashed()) {
                $bill->restore();
            }

            $bill->import_batch_id = $importBatchId;

            if ($bill->status !== BillStatus::Void) {
                $bill->status = BillStatus::for($bill->amount_kobo, $bill->paid_kobo, $bill->due_date->toDateString(), BillDates::today()->toDateString());
            }

            $bill->save();

            activity(BillIssuer::LOG)
                ->event('bill.import_restored')
                ->performedOn($bill)
                ->withProperties(['number' => $bill->number, 'amount_kobo' => $bill->amount_kobo->kobo, 'import_batch_id' => $importBatchId])
                ->log('Opening balance brought back by an import');

            return $this->ledger->balance($bill->id);
        }, 3);
    }
}
