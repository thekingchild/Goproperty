<?php

declare(strict_types=1);

namespace Modules\Payments\Receipts;

use App\Documents\Parts\DocumentDates;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Modules\Billing\Contracts\BillBalance;
use Modules\Billing\Contracts\BillLedger;
use Modules\Leasing\Contracts\LeaseSummary;
use Modules\Leasing\Contracts\TenantLeases;
use Modules\Payments\Contracts\PaymentMethod;
use Modules\Payments\Contracts\PaymentSource;
use Modules\Payments\Credit\TenantCredit;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\PaymentAllocation;
use Modules\Payments\Notices\TenantNotices;
use Modules\Properties\Contracts\PropertyDirectory;
use Modules\Properties\Contracts\UnitDirectory;
use Modules\Tenants\Contracts\TenantDirectory;

/**
 * What a receipt prints that could change after it is issued
 * (BRANDING_DOCUMENTS.md § 3 items 3–6, 8), taken once at issue into
 * `receipts.details` (P03-10b): the tenant (name, phone, email), the
 * property and lease (from the first bill the payment settled, else the
 * tenant's latest lease), the payment (method, date, reference, how it was
 * confirmed), each bill it was applied to, the tenant's balance and credit
 * just after it, who confirmed it and when, and where a copy went. Read
 * through the Tenants, Properties, Leasing and Billing contracts only, and
 * back by key (never by key order: MySQL reorders JSON objects).
 */
final readonly class ReceiptDetails
{
    public function __construct(
        private TenantDirectory $tenants,
        private PropertyDirectory $buildings,
        private UnitDirectory $units,
        private TenantLeases $leases,
        private BillLedger $ledger,
        private TenantCredit $credit,
        private TenantNotices $notices,
    ) {}

    /** @return array<string, mixed> */
    public function take(Payment $payment, Organization $organization): array
    {
        $tenant = $this->tenants->find($payment->tenant_profile_id);
        $allocations = [];
        $first = null;

        foreach (PaymentAllocation::query()->where('payment_id', $payment->id)->orderBy('id')->get() as $allocation) {
            $bill = $this->ledger->balance($allocation->bill_id);
            $first ??= $bill;
            $allocations[] = [
                'bill_id' => $allocation->bill_id,
                'invoice_number' => $bill !== null ? $bill->number : '',
                'description' => $bill !== null ? self::billDescription($bill) : '',
                'amount_kobo' => $allocation->amount_kobo->kobo,
                'settled' => $bill !== null && $bill->status === 'paid',
            ];
        }

        $outstanding = Money::sum(array_map(static fn (BillBalance $bill): Money => $bill->outstanding, $this->ledger->openBills($payment->tenant_profile_id)));

        return [
            'tenant' => [
                'name' => $tenant !== null ? $tenant->name : __('Unknown tenant'),
                'phone' => $tenant?->phone,
                'email' => $tenant?->email,
            ],
            'property' => $this->property($payment, $first),
            'payment' => [
                'method' => $payment->method->value,
                'paid_on' => $payment->paid_at->toDateString(),
                'reference' => $payment->method === PaymentMethod::Paystack ? ($payment->paystack_reference ?? $payment->reference) : $payment->reference,
                'source' => $payment->source->value,
            ],
            'allocations' => $allocations,
            'outstanding_after_kobo' => $outstanding->kobo,
            'credit_balance_kobo' => $this->credit->balance($payment->tenant_profile_id)->kobo,
            'confirmed_by' => self::confirmedBy($payment),
            'confirmed_at' => ($payment->confirmed_at ?? CarbonImmutable::now())->toIso8601String(),
            // Imported history is never sent to the tenant (P03-13b).
            'sent_via' => $tenant !== null && $payment->source !== PaymentSource::Import ? $this->notices->channelsFor($tenant, $organization)->printed() : [],
        ];
    }

    /** "Rent · 01/01/2027 – 31/01/2027". */
    public static function billDescription(BillBalance $bill): string
    {
        $type = ucfirst(str_replace('_', ' ', $bill->type));

        if ($bill->periodStart === null || $bill->periodEnd === null) {
            return $type;
        }

        return $type.' · '.DocumentDates::day($bill->periodStart).' – '.DocumentDates::day($bill->periodEnd);
    }

    /** The person who confirmed it, or how the system did (BRANDING_DOCUMENTS.md § 3 item 6). */
    public static function confirmedBy(Payment $payment): string
    {
        $name = $payment->confirmed_by_user_id !== null ? User::query()->whereKey($payment->confirmed_by_user_id)->value('name') : null;

        if (is_string($name) && trim($name) !== '') {
            return $name;
        }

        return match ($payment->source) {
            PaymentSource::PaystackWebhook => 'Automatically — Paystack',
            PaymentSource::StatementMatch => 'Statement match',
            PaymentSource::Import => 'Imported from earlier records',
            default => 'Automatically',
        };
    }

    /** @return array{building: string, unit: string|null, address: string|null, lease_number: string|null, cycle: string|null} */
    private function property(Payment $payment, ?BillBalance $bill): array
    {
        $leases = $this->leases->forTenant($payment->tenant_profile_id);
        $lease = null;

        foreach ($leases as $candidate) {
            if ($bill !== null && $candidate->id === $bill->leaseId) {
                $lease = $candidate;
            }
        }

        $lease ??= $bill === null ? ($leases[0] ?? null) : null;
        $propertyId = $bill !== null ? $bill->propertyId : $lease?->propertyId;
        $unitId = $bill !== null ? $bill->unitId : $lease?->unitId;
        $building = $propertyId !== null ? $this->buildings->find($propertyId) : null;
        $unit = $unitId !== null ? $this->units->find($unitId) : null;

        return [
            'building' => $building !== null ? $building->name : ($unit !== null ? $unit->propertyName : '—'),
            'unit' => $unit?->label,
            'address' => $building !== null ? self::address($building->address, $building->state) : null,
            'lease_number' => $lease instanceof LeaseSummary ? $lease->contractNumber : null,
            'cycle' => $lease instanceof LeaseSummary ? $lease->cycle->label() : null,
        ];
    }

    private static function address(string $address, string $state): ?string
    {
        $address = trim($address);
        $line = $state === '' || str_contains(mb_strtolower($address), mb_strtolower($state)) ? $address : ($address === '' ? $state : $address.', '.$state);

        return $line === '' ? null : $line;
    }
}
