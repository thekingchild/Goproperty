<?php

declare(strict_types=1);

namespace App\Documents;

use App\Support\Money;

/**
 * A sample page of the document kit (resources/views/documents/kit-sample):
 * every shared partial with fixed, made-up content, so the two PDF drivers
 * can be compared on it (BRANDING_DOCUMENTS.md § 5). No real records.
 */
final class KitSample
{
    public const string VERIFICATION_CODE = '7KQ2MX9P4D';

    public static function document(DocumentBranding $brand): Document
    {
        $rows = [
            ['description' => 'Rent — 2 bedroom flat', 'note' => 'Annual rent, renewal year 3', 'period' => '2027', 'kobo' => 250_000_000],
            ['description' => 'Service charge', 'note' => 'Cleaning, generator, common areas', 'period' => '2027', 'kobo' => 35_000_000],
            ['description' => 'Security levy', 'note' => 'Gate and night patrol', 'period' => '2027', 'kobo' => 6_000_000],
            ['description' => 'Waste management (LAWMA)', 'note' => 'Monthly collection', 'period' => '2027', 'kobo' => 2_400_000],
            ['description' => 'Water rate', 'note' => 'Borehole maintenance share', 'period' => '2027', 'kobo' => 1_850_050],
            ['description' => 'Caution fee', 'note' => 'Refundable at move-out', 'period' => 'Once', 'kobo' => 25_000_000],
        ];

        $total = Money::sum(array_map(static fn (array $row): Money => Money::kobo($row['kobo']), $rows));

        return new ViewDocument('documents.kit-sample', [
            'title' => 'Statement',
            'number' => 'ST-ADR-2027-000007',
            'date' => '14/12/2026',
            'rows' => array_map(static fn (array $row): array => [...$row, 'amount' => Money::kobo($row['kobo'])->format()], $rows),
            'total' => $total->format(),
            'words' => $total->inWords(),
            'notes' => 'Pay by bank transfer to the account on your invoice, with your payment reference in the narration.',
            'verificationCode' => self::VERIFICATION_CODE,
        ], $brand, 'ST-ADR-2027-000007.pdf');
    }
}
