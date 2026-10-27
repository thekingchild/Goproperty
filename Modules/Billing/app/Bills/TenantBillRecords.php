<?php

declare(strict_types=1);

namespace Modules\Billing\Bills;

use App\Banking\BankAccounts;
use App\Documents\DocumentRenderer;
use App\Documents\Invoice\BankTransfer;
use App\Documents\Parts\DocumentPeriod;
use App\Models\OrganizationBankAccount;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Modules\Billing\Contracts\PaymentOption;
use Modules\Billing\Contracts\PaymentOptions;
use Modules\Billing\Contracts\TenantBill;
use Modules\Billing\Contracts\TenantBillPayment;
use Modules\Billing\Contracts\TenantBills;
use Modules\Billing\Documents\Invoices;
use Modules\Billing\Enums\BillStatus;
use Modules\Billing\Models\Bill;
use Modules\Billing\Models\BillItem;

/**
 * The Billing module's answers to Contracts\TenantBills (the tenant
 * portal, P03-11a), read through the organization scope and only among
 * the given tenants' bills, so a guessed id of anyone else's bill is not
 * found. The bill page shows what the public pay page shows
 * (Pay\PayPages): the branding as issued, the money now, the
 * organization's primary usable bank account and the online payment
 * options; the invoice is the same web page members open (P03-05c).
 * Read-only.
 */
final readonly class TenantBillRecords implements TenantBills
{
    public function __construct(
        private Invoices $invoices,
        private BillDetails $details,
        private BankAccounts $banks,
        private PaymentOptions $options,
        private DocumentRenderer $renderer,
    ) {}

    public function forTenants(array $tenantProfileIds): array
    {
        if ($tenantProfileIds === []) {
            return [];
        }

        return array_values(self::of($tenantProfileIds)
            ->orderByDesc('due_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Bill $bill): TenantBill => $this->summary($bill))
            ->all());
    }

    public function find(int $billId, array $tenantProfileIds): ?TenantBill
    {
        $bill = $this->bill($billId, $tenantProfileIds);

        return $bill instanceof Bill ? $this->summary($bill) : null;
    }

    public function payment(int $billId, array $tenantProfileIds): ?TenantBillPayment
    {
        $bill = $this->bill($billId, $tenantProfileIds);

        if (! $bill instanceof Bill) {
            return null;
        }

        $payable = $this->invoices->payable($bill);
        $account = $payable !== null ? $this->banks->primary() : null;

        return new TenantBillPayment(
            bill: $this->summary($bill),
            lines: array_values(BillItem::query()->where('bill_id', $bill->id)->orderBy('id')->get()
                ->map(static fn (BillItem $item): array => [
                    'label' => $item->label,
                    'note' => $item->note,
                    'period' => self::period($item->period_start, $item->period_end),
                    'amount' => $item->amount_kobo->format(),
                ])->all()),
            bank: $account instanceof OrganizationBankAccount ? new BankTransfer($account->bank_name, $account->account_name, $account->account_number) : null,
            options: $payable === null ? [] : array_map(static fn (PaymentOption $option): string => $option->render($payable)->toHtml(), $this->options->for($payable)),
            payUrl: route('pay.show', $bill->payment_reference),
            voidReason: $bill->status === BillStatus::Void ? $bill->void_reason : null,
        );
    }

    public function invoicePage(int $billId, array $tenantProfileIds): ?string
    {
        $bill = $this->bill($billId, $tenantProfileIds);

        return $bill instanceof Bill ? $this->renderer->web($this->invoices->document($bill)) : null;
    }

    /** @param  list<int>  $tenantProfileIds */
    private function bill(int $billId, array $tenantProfileIds): ?Bill
    {
        if ($tenantProfileIds === []) {
            return null;
        }

        $bill = self::of($tenantProfileIds)->whereKey($billId)->first();

        return $bill instanceof Bill ? $bill : null;
    }

    /**
     * @param  list<int>  $tenantProfileIds
     * @return Builder<Bill>
     */
    private static function of(array $tenantProfileIds): Builder
    {
        return Bill::query()->whereIn('tenant_profile_id', $tenantProfileIds);
    }

    private function summary(Bill $bill): TenantBill
    {
        $parties = $this->details->of($bill);
        $property = $parties->buildingName !== null
            ? ($parties->unit !== null ? $parties->buildingName.' — '.$parties->unit : $parties->buildingName)
            : $parties->unit;

        return new TenantBill(
            id: $bill->id,
            organizationId: $bill->organization_id,
            tenantProfileId: $bill->tenant_profile_id,
            leaseId: $bill->lease_id,
            number: $bill->number,
            paymentReference: $bill->payment_reference,
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
            voidedAt: $bill->voided_at,
            property: $property,
            brand: $this->invoices->brand($bill),
        );
    }

    private static function period(?CarbonImmutable $start, ?CarbonImmutable $end): ?string
    {
        return $start !== null && $end !== null ? (new DocumentPeriod($start, $end))->label() : null;
    }
}
