<?php

declare(strict_types=1);

namespace Modules\Payments\Payments;

use App\Support\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Modules\Payments\Contracts\CollectedPayment;
use Modules\Payments\Contracts\CollectionVolumes;
use Modules\Payments\Contracts\PaymentMethod;
use Modules\Payments\Contracts\PaymentSource;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Models\Payment;

/** Contracts\CollectionVolumes over the organization's payments (reads only). */
final readonly class CollectionVolumeRecords implements CollectionVolumes
{
    public function collectedBetween(DateTimeInterface $from, DateTimeInterface $to): Money
    {
        return Money::kobo((int) self::counted()->where('paid_at', '>=', self::moment($from))->where('paid_at', '<', self::moment($to))->sum('amount_kobo'));
    }

    public function largestBetween(DateTimeInterface $from, DateTimeInterface $to): Money
    {
        return Money::kobo((int) self::counted()->where('paid_at', '>=', self::moment($from))->where('paid_at', '<', self::moment($to))->max('amount_kobo'));
    }

    public function payment(int $paymentId): ?CollectedPayment
    {
        $payment = self::counted()->whereKey($paymentId)->first();

        return $payment instanceof Payment ? self::collected($payment) : null;
    }

    public function fromTenant(int $tenantProfileId, DateTimeInterface $from, DateTimeInterface $to, array $methods): array
    {
        return array_values(self::counted()
            ->where('tenant_profile_id', $tenantProfileId)
            ->whereIn('method', array_map(static fn (PaymentMethod $method): string => $method->value, $methods))
            ->where('paid_at', '>=', self::moment($from))
            ->where('paid_at', '<', self::moment($to))
            ->orderBy('paid_at')
            ->orderBy('id')
            ->get()
            ->map(self::collected(...))
            ->all());
    }

    /** Moments are stored in the application's time zone: a Lagos midnight is compared as that instant. */
    private static function moment(DateTimeInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone(date_default_timezone_get());
    }

    /** @return Builder<Payment> */
    private static function counted(): Builder
    {
        return Payment::query()
            ->whereIn('status', [PaymentStatus::Confirmed->value, PaymentStatus::Refunded->value])
            ->where('source', '!=', PaymentSource::Import->value);
    }

    private static function collected(Payment $payment): CollectedPayment
    {
        return new CollectedPayment(
            id: $payment->id,
            tenantProfileId: $payment->tenant_profile_id,
            amount: $payment->amount_kobo,
            method: $payment->method,
            payerType: $payment->payer_type,
            source: $payment->source,
            paidAt: CarbonImmutable::instance($payment->paid_at),
        );
    }
}
