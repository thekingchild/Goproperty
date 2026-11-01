<?php

declare(strict_types=1);

namespace App\Compliance\Monitoring;

use App\Compliance\OrganizationTierLimits;
use App\Compliance\TransactionTiers;
use App\Enums\ComplianceFlagType;
use App\Models\ComplianceFlag;
use App\Models\Organization;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Modules\Payments\Contracts\CollectedPayment;
use Modules\Payments\Contracts\CollectionVolumes;
use Modules\Payments\Contracts\PaymentMethod;

/**
 * The checks on each confirmed tenant payment (P06-09;
 * ONBOARDING_COMPLIANCE.md § 5.4 "checks on each payment confirmation",
 * defaults 90–91 and 93), queued by CheckConfirmedPayment after the
 * confirmation commits:
 *
 * - `single_over`: above the single-payment limit that applies to the
 *   organization (owners and queue); with no cap (Tier 3), above the
 *   tier's review amount (queue only);
 * - `cash_threshold`: cash at or above the MLPPA threshold for the payer
 *   (a person or a company; owners and queue);
 * - `possible_structuring`: the tenant's cash or transfer payments, each
 *   from `structuring_near_percent` of their threshold up to just under it,
 *   at least `structuring_payments` of them within
 *   `structuring_window_days` (queue only; at most one per tenant in a
 *   window);
 *
 * then the volume flags and restriction (TierMonitor::evaluate). Each flag
 * is keyed by its payment, so a re-run raises nothing twice.
 */
final readonly class PaymentChecks
{
    public function __construct(
        private OrganizationContext $context,
        private TransactionTiers $tiers,
        private CollectionVolumes $volumes,
        private ComplianceFlags $flags,
        private MonitoringRules $rules,
        private TierMonitor $monitor,
    ) {}

    /** @return list<ComplianceFlag> the flags raised */
    public function check(Organization $organization, int $paymentId): array
    {
        $raised = $this->context->run($organization, fn (): array => DB::transaction(function () use ($organization, $paymentId): array {
            $locked = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $payment = $this->volumes->payment($paymentId);

            if (! $payment instanceof CollectedPayment) {
                return [];
            }

            $limits = $this->tiers->forOrganization($locked);
            $raised = array_values(array_filter([
                $this->singleOver($locked, $payment, $limits),
                $this->cashThreshold($locked, $payment),
                $this->structuring($locked, $payment, $limits),
            ]));

            $this->monitor->tellOwners($locked, $raised);

            return $raised;
        }, 3));

        return [...$raised, ...$this->monitor->evaluate($organization)];
    }

    private function singleOver(Organization $organization, CollectedPayment $payment, OrganizationTierLimits $limits): ?ComplianceFlag
    {
        $limit = $limits->singlePaymentLimit();
        $review = $limit === null ? $limits->terms->reviewThreshold : null;
        $line = $limit ?? $review;

        if ($line === null || ! $payment->amount->greaterThan($line)) {
            return null;
        }

        return $this->flags->raise(ComplianceFlagType::SingleOver, "single_over:{$payment->id}", [
            ...$this->aboutPayment($organization, $payment),
            'limit_kobo' => $line->kobo,
            'details' => ['review' => $limit === null, 'method' => $payment->method->value, 'paid_on' => MonitoringRules::day($payment->paidAt)->toDateString()],
        ]);
    }

    private function cashThreshold(Organization $organization, CollectedPayment $payment): ?ComplianceFlag
    {
        $threshold = $this->rules->cashThreshold($payment->payerType);

        if ($payment->method !== PaymentMethod::Cash || $payment->amount->lessThan($threshold)) {
            return null;
        }

        return $this->flags->raise(ComplianceFlagType::CashThreshold, "cash_threshold:{$payment->id}", [
            ...$this->aboutPayment($organization, $payment),
            'limit_kobo' => $threshold->kobo,
            'details' => ['payer_type' => $payment->payerType->value, 'paid_on' => MonitoringRules::day($payment->paidAt)->toDateString()],
        ]);
    }

    private function structuring(Organization $organization, CollectedPayment $payment, OrganizationTierLimits $limits): ?ComplianceFlag
    {
        $methods = [PaymentMethod::Cash, PaymentMethod::BankTransfer];

        $threshold = $this->threshold($payment, $limits);

        if (! in_array($payment->method, $methods, true) || ! $threshold instanceof Money || ! $this->justBelow($payment, $limits)) {
            return null;
        }

        $days = $this->rules->structuringWindowDays();

        if (ComplianceFlag::query()
            ->where('type', ComplianceFlagType::PossibleStructuring->value)
            ->where('tenant_profile_id', $payment->tenantProfileId)
            ->where('created_at', '>=', now()->subDays($days))
            ->exists()) {
            return null;
        }

        $near = array_values(array_filter(
            $this->volumes->fromTenant($payment->tenantProfileId, $payment->paidAt->subDays($days), $payment->paidAt->addDays($days)->addSecond(), $methods),
            fn (CollectedPayment $other): bool => $this->justBelow($other, $limits),
        ));
        $window = $this->windowWith($near, $payment, $days);

        if (count($window) < $this->rules->structuringPayments()) {
            return null;
        }

        return $this->flags->raise(ComplianceFlagType::PossibleStructuring, "possible_structuring:{$organization->id}:{$payment->tenantProfileId}:{$payment->id}", [
            ...$this->aboutPayment($organization, $payment),
            'metric_value_kobo' => Money::sum(array_map(static fn (CollectedPayment $one): Money => $one->amount, $window))->kobo,
            'limit_kobo' => $threshold->kobo,
            'details' => [
                'payment_ids' => array_map(static fn (CollectedPayment $one): int => $one->id, $window),
                'window_days' => $days,
            ],
        ]);
    }

    /**
     * The most payments within any `$days` that include $payment.
     *
     * @param  list<CollectedPayment>  $near  oldest first
     * @return list<CollectedPayment>
     */
    private function windowWith(array $near, CollectedPayment $payment, int $days): array
    {
        $best = [];

        foreach ($near as $start) {
            $end = $start->paidAt->addDays($days);
            $window = array_values(array_filter($near, static fn (CollectedPayment $one): bool => $one->paidAt->greaterThanOrEqualTo($start->paidAt) && $one->paidAt->lessThan($end)));
            $includes = array_filter($window, static fn (CollectedPayment $one): bool => $one->id === $payment->id) !== [];

            if ($includes && count($window) > count($best)) {
                $best = $window;
            }
        }

        return $best;
    }

    /** The threshold a payment could be kept under: cash's MLPPA line, or the single-payment limit for a transfer. */
    private function threshold(CollectedPayment $payment, OrganizationTierLimits $limits): ?Money
    {
        return $payment->method === PaymentMethod::Cash
            ? $this->rules->cashThreshold($payment->payerType)
            : $limits->singlePaymentLimit();
    }

    private function justBelow(CollectedPayment $payment, OrganizationTierLimits $limits): bool
    {
        $threshold = $this->threshold($payment, $limits);

        return $threshold !== null
            && $payment->amount->lessThan($threshold)
            && $payment->amount->times(100)->greaterThanOrEqual($threshold->times($this->rules->structuringNearPercent()));
    }

    /** @return array<string, mixed> */
    private function aboutPayment(Organization $organization, CollectedPayment $payment): array
    {
        return [
            'tier' => $organization->transaction_tier,
            'subject_type' => 'payment',
            'subject_id' => $payment->id,
            'tenant_profile_id' => $payment->tenantProfileId,
            'metric_value_kobo' => $payment->amount->kobo,
        ];
    }
}
