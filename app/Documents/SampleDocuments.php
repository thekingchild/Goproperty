<?php

declare(strict_types=1);

namespace App\Documents;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The sample invoice and receipt of the branding preview (Settings →
 * Branding, BRANDING_DOCUMENTS.md § 1–3): made-up tenant, property, lines
 * and payment, numbered with the organization's own prefixes, in the shape
 * the document templates take. Real documents (P03-05) pass the same keys
 * from bills and receipts. Nothing here comes from the organization's
 * records, so the preview never shows bank details or tenants (it is
 * open to administrators and to support staff, who may see neither).
 */
final class SampleDocuments
{
    /** @return array<string, mixed> */
    public static function invoice(DocumentBranding $brand): array
    {
        $year = CarbonImmutable::now('Africa/Lagos')->year;
        $issued = CarbonImmutable::create($year, 11, 1, 0, 0, 0, 'Africa/Lagos');
        $due = $issued->endOfYear();
        $period = '01/01/'.($year + 1).' – 31/12/'.($year + 1);
        $items = [
            ['description' => 'Rent — 2 bedroom flat', 'note' => 'Annual rent, renewal year 3', 'period' => $period, 'amount' => Money::kobo(250_000_000)],
            ['description' => 'Service charge', 'note' => 'Cleaning, generator, common areas', 'period' => $period, 'amount' => Money::kobo(35_000_000)],
            ['description' => 'Security levy', 'note' => null, 'period' => $period, 'amount' => Money::kobo(6_000_000)],
            ['description' => 'Waste management (LAWMA)', 'note' => null, 'period' => $period, 'amount' => Money::kobo(2_400_000)],
        ];
        $subtotal = Money::sum(array_column($items, 'amount'));
        $credit = Money::kobo(3_400_000);
        $amountDue = $subtotal->minus($credit);

        return [
            'number' => $brand->number($brand->branding->invoicePrefix, $year + 1, 142),
            'issued_on' => $issued->format('d/m/Y'),
            'due_on' => $due->format('d/m/Y'),
            'due_on_long' => $due->format('j M Y'),
            'status' => 'UNPAID',
            'billed_to' => ['name' => 'Chiamaka Okafor', 'lines' => ['+234 812 345 6789', 'chiamaka.okafor@example.com']],
            'property' => ['name' => 'Palm Court Apartments — Flat 3B', 'lines' => ['22 Fola Osibo Road, Lekki Phase 1, Lagos', 'Lease '.$brand->number($brand->branding->leasePrefix, $year - 2, 31).' · Annual']],
            'items' => array_map(static fn (array $item): array => [...$item, 'amount' => $item['amount']->format()], $items),
            'totals' => [
                ['label' => 'Subtotal', 'value' => $subtotal->format()],
                ['label' => 'Credit applied', 'value' => '− '.$credit->format()],
                ['label' => 'Paid to date', 'value' => Money::zero()->format()],
            ],
            'amount_due' => $amountDue->format(),
            'bank' => ['bank' => 'Your bank', 'account_name' => $brand->name(), 'account_number' => '0123456789'],
            'payment_reference' => 'GP-7KQ2M',
            'online' => ['label' => 'Pay '.$amountDue->format().' online', 'link' => url('/pay/GP-7KQ2M')],
            'verification_code' => '7KQ2MX9P4D',
            'verify_url' => url('/verify'),
        ];
    }

    /** @return array<string, mixed> */
    public static function receipt(DocumentBranding $brand): array
    {
        $year = CarbonImmutable::now('Africa/Lagos')->year;
        $amount = Money::kobo(290_000_000);

        return [
            'number' => $brand->number($brand->branding->receiptPrefix, $year, 318),
            'issued_on' => '14/12/'.$year,
            'tenant_id' => 'TN-0087',
            'amount' => $amount->format(),
            'amount_in_words' => $amount->inWords(),
            'received_from' => [
                ['label' => 'Tenant', 'value' => 'Chiamaka Okafor'],
                ['label' => 'Phone', 'value' => '+234 812 345 6789'],
                ['label' => 'Property', 'value' => 'Palm Court Apartments — Flat 3B'],
                ['label' => 'Lease', 'value' => $brand->number($brand->branding->leasePrefix, $year - 2, 31)],
            ],
            'payment' => [
                ['label' => 'Method', 'value' => 'Bank transfer'],
                ['label' => 'Date paid', 'value' => '13/12/'.$year],
                ['label' => 'Paid into', 'value' => 'Your bank ···6789'],
                ['label' => 'Bank narration', 'value' => 'GP-7KQ2M C OKAFOR RENT'],
                ['label' => 'Confirmed', 'value' => 'Matched from bank statement'],
            ],
            'applied' => [
                ['invoice' => $brand->number($brand->branding->invoicePrefix, $year + 1, 142), 'description' => 'Rent, service charge, security levy, waste — '.($year + 1), 'amount' => $amount->format(), 'paid' => true],
            ],
            'summary' => [
                ['label' => 'Total applied', 'value' => $amount->format()],
                ['label' => 'Outstanding balance after this payment', 'value' => Money::zero()->format()],
                ['label' => 'Credit balance', 'value' => Money::zero()->format()],
            ],
            'confirmed_by' => 'Tunde Bakare',
            'confirmed_at' => '14/12/'.$year.', 10:42 WAT',
            'verification_code' => 'R8X4P2HC7N',
            'verify_url' => url('/verify'),
            'voided' => false,
        ];
    }
}
