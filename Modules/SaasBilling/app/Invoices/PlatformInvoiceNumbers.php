<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Invoices;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * GoProperty's invoice numbers, `PR-{YYYY}-{seq}` (DATABASE_SCHEMA.md § 3):
 * one platform-wide sequence per Lagos year in `platform_invoice_counters`,
 * taken under the counter row's lock inside the caller's transaction, so a
 * rolled-back invoice gives its number back (never max(id)+1).
 */
final class PlatformInvoiceNumbers
{
    public const string PREFIX = 'PR';

    public function next(): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Take an invoice number inside the transaction that writes the invoice.');
        }

        $year = (int) CarbonImmutable::now('Africa/Lagos')->format('Y');
        DB::table('platform_invoice_counters')->insertOrIgnore(['year' => $year, 'last_seq' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $last = (int) DB::table('platform_invoice_counters')->where('year', $year)->lockForUpdate()->value('last_seq');
        DB::table('platform_invoice_counters')->where('year', $year)->update(['last_seq' => $last + 1, 'updated_at' => now()]);

        return sprintf('%s-%d-%06d', self::PREFIX, $year, $last + 1);
    }
}
