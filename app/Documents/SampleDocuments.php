<?php

declare(strict_types=1);

namespace App\Documents;

use App\Documents\Invoice\BankTransfer;
use App\Documents\Invoice\InvoiceDocument;
use App\Documents\Invoice\InvoiceLine;
use App\Documents\Invoice\InvoiceStatus;
use App\Documents\Invoice\OnlinePayment;
use App\Documents\Parts\DocumentPeriod;
use App\Documents\Parts\DocumentProperty;
use App\Documents\Parts\DocumentTenant;
use App\Documents\Receipt\ReceiptAllocation;
use App\Documents\Receipt\ReceiptDocument;
use App\Documents\Receipt\ReceiptPayment;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The sample invoice and receipt of the branding preview (Settings →
 * Branding, BRANDING_DOCUMENTS.md § 1–3), as in the design templates:
 * made-up tenant, property, lines and payment, numbered with the
 * organization's own prefixes, built as the same InvoiceDocument and
 * ReceiptDocument real bills and receipts use. Nothing here comes from the
 * organization's records, so the preview never shows bank details or
 * tenants (it is open to administrators and to support staff, who may see
 * neither).
 */
final class SampleDocuments
{
    public const string INVOICE_CODE = '7KQ2MX9P4D';

    public const string RECEIPT_CODE = 'R8X4P2HC7N';

    public static function invoice(DocumentBranding $brand): InvoiceDocument
    {
        $year = CarbonImmutable::now('Africa/Lagos')->year;
        $issued = CarbonImmutable::create($year, 11, 1, 0, 0, 0, 'Africa/Lagos');
        $period = new DocumentPeriod(CarbonImmutable::create($year + 1, 1, 1, 0, 0, 0, 'Africa/Lagos'), CarbonImmutable::create($year + 1, 12, 31, 0, 0, 0, 'Africa/Lagos'));
        $lines = [
            new InvoiceLine('Rent — 2 bedroom flat', Money::kobo(250_000_000), 'Annual rent, renewal year 3'),
            new InvoiceLine('Service charge', Money::kobo(35_000_000), 'Cleaning, generator, common areas'),
            new InvoiceLine('Security levy', Money::kobo(6_000_000)),
            new InvoiceLine('Waste management (LAWMA)', Money::kobo(2_400_000)),
        ];

        return new InvoiceDocument(
            brand: $brand,
            number: $brand->number($brand->branding->invoicePrefix, $year + 1, 142),
            issuedOn: $issued,
            dueOn: $issued->endOfYear()->startOfDay(),
            status: InvoiceStatus::Unpaid,
            tenant: self::tenant(),
            property: self::property($brand, $year),
            lines: $lines,
            paymentReference: 'GP-7KQ2M',
            verificationCode: self::INVOICE_CODE,
            bank: new BankTransfer('Your bank', $brand->name(), '0123456789'),
            online: new OnlinePayment(url('/pay/GP-7KQ2M')),
            period: $period,
            credit: Money::kobo(3_400_000),
        );

    }

    public static function receipt(DocumentBranding $brand): ReceiptDocument
    {
        $year = CarbonImmutable::now('Africa/Lagos')->year;
        $amount = Money::kobo(290_000_000);

        return new ReceiptDocument(
            brand: $brand,
            number: $brand->number($brand->branding->receiptPrefix, $year, 318),
            issuedAt: CarbonImmutable::create($year, 12, 14, 10, 42, 0, 'Africa/Lagos'),
            amount: $amount,
            tenant: self::tenant(),
            property: self::property($brand, $year),
            payment: new ReceiptPayment(
                method: 'Bank transfer',
                paidOn: CarbonImmutable::create($year, 12, 13, 0, 0, 0, 'Africa/Lagos'),
                confirmation: 'Matched from bank statement',
                bank: 'Your bank',
                accountNumber: '0123456789',
                reference: 'GP-7KQ2M C OKAFOR RENT',
            ),
            allocations: [
                new ReceiptAllocation($brand->number($brand->branding->invoicePrefix, $year + 1, 142), 'Rent, service charge, security levy, waste — '.($year + 1), $amount, settled: true),
            ],
            outstandingAfter: Money::zero(),
            creditBalance: Money::zero(),
            confirmedBy: 'Tunde Bakare',
            confirmedAt: CarbonImmutable::create($year, 12, 14, 10, 42, 0, 'Africa/Lagos'),
            verificationCode: self::RECEIPT_CODE,
            sentVia: ['WhatsApp', 'email'],
        );
    }

    private static function tenant(): DocumentTenant
    {
        return new DocumentTenant('Chiamaka Okafor', '+234 812 345 6789', 'chiamaka.okafor@example.com', 'TN-0087');
    }

    private static function property(DocumentBranding $brand, int $year): DocumentProperty
    {
        return new DocumentProperty('Palm Court Apartments', 'Flat 3B', '22 Fola Osibo Road, Lekki Phase 1, Lagos', $brand->number($brand->branding->leasePrefix, $year - 2, 31), 'Annual');
    }
}
