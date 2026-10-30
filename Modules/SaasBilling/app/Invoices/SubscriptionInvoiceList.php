<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Invoices;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Modules\SaasBilling\Contracts\InvoiceLine;
use Modules\SaasBilling\Contracts\SubscriptionInvoice;
use Modules\SaasBilling\Contracts\SubscriptionInvoices;
use Modules\SaasBilling\Enums\InvoiceKind;
use Modules\SaasBilling\Enums\InvoiceStatus;
use Modules\SaasBilling\Metering\BillingPeriod;
use Modules\SaasBilling\Metering\SubscriptionMetering;
use Modules\SaasBilling\Models\PlatformInvoice;

/**
 * Subscription invoices as the customer sees them (P06-02): on Settings →
 * Subscription and in the invoice email. Every figure comes from what the
 * invoice kept when it was issued, never from today's plan.
 */
final readonly class SubscriptionInvoiceList implements SubscriptionInvoices
{
    public function recent(int $limit = 12): array
    {
        return array_values(PlatformInvoice::query()
            ->where('kind', InvoiceKind::Subscription)
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(static fn (PlatformInvoice $invoice): SubscriptionInvoice => self::present($invoice))
            ->all());
    }

    public static function present(PlatformInvoice $invoice): SubscriptionInvoice
    {
        $covered = $invoice->status === InvoiceStatus::Paid && $invoice->notes === SubscriptionMetering::COVERED_NOTE;
        $vatPercent = $invoice->vat_percent ?? '7.5';

        return new SubscriptionInvoice(
            number: $invoice->number,
            planName: (string) $invoice->plan_name,
            period: $invoice->period_start !== null && $invoice->period_end !== null
                ? BillingPeriod::describe(self::day($invoice->period_start), self::day($invoice->period_end))
                : '',
            units: (int) $invoice->units_billed,
            lines: self::lines($invoice),
            subtotalLabel: self::subtotalLabel($invoice),
            subtotal: $invoice->subtotal_kobo,
            vatLabel: __('VAT (:percent%)', ['percent' => $vatPercent]),
            vat: $invoice->vat_kobo,
            total: $invoice->total_kobo,
            status: $invoice->status->value,
            statusLabel: $covered ? __('Covered by GoProperty') : __($invoice->status->label()),
            covered: $covered,
            dueDate: $invoice->due_date,
            paidAt: $invoice->paid_at,
            issuedAt: $invoice->created_at,
        );
    }

    /** @return list<InvoiceLine> */
    private static function lines(PlatformInvoice $invoice): array
    {
        $base = $invoice->base_price_kobo ?? Money::zero();
        $lines = [new InvoiceLine(
            __(':plan plan, :units units included (a month)', ['plan' => $invoice->plan_name, 'units' => number_format((int) $invoice->included_units)]),
            $base,
        )];

        $extra = (int) $invoice->extra_units;

        if ($extra > 0 && $invoice->unit_price_kobo !== null) {
            $lines[] = new InvoiceLine(
                trans_choice(':count extra unit × :price (a month)|:count extra units × :price (a month)', $extra, ['count' => number_format($extra), 'price' => $invoice->unit_price_kobo->format()]),
                $invoice->unit_price_kobo->times($extra),
            );
        }

        return $lines;
    }

    private static function subtotalLabel(PlatformInvoice $invoice): string
    {
        if ($invoice->days_billed !== null && $invoice->days_in_month !== null) {
            return __('Subtotal for :days of :month days', ['days' => $invoice->days_billed, 'month' => $invoice->days_in_month]);
        }

        if ((int) $invoice->months_billed > 1) {
            return __('Subtotal for 12 months, priced as :months', ['months' => $invoice->months_billed]);
        }

        return __('Subtotal');
    }

    private static function day(CarbonImmutable $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->toDateString(), 'Africa/Lagos');
    }
}
