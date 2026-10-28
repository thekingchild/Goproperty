<?php

declare(strict_types=1);

use App\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Modules\Import\Models\ImportBatch;
use Modules\Payments\Models\OpeningBalance;
use Modules\Payments\Models\TenantCreditEntry;

/*
 * Helpers for the Opening balances, Utilities and Payment history import
 * sheets (P03-13b). Imports run through the real engine (upload, dry run,
 * commit on the sync queue, rollback) with the Leases sheet's standard
 * workbook (P03-13a: Palm Court, Flats 1–3, Adaeze, Bayo and Chika, their
 * leases); bills come from the scheduled `bills-generate` job. Every name,
 * phone and email is fixed.
 */

require_once __DIR__.'/../../../../Leasing/tests/Feature/Import/helpers.php';
require_once __DIR__.'/../receipt-helpers.php';
require_once __DIR__.'/../../../../Billing/tests/Feature/utility-bill-helpers.php';

/** The sheets' headers, as the template has them. */
const OPENING_BALANCES_HEADER = ['Your ID', 'Tenant', 'Building', 'Unit', 'Amount owed', 'As-of date', 'Description'];

const UTILITIES_HEADER = ['Your ID', 'Building', 'Unit', 'Utility', 'Metered', 'Amount', 'Billing cycle', 'Unit of measure', 'Last meter reading', 'Paid until'];

const PAYMENT_HISTORY_HEADER = ['Your ID', 'Tenant', 'Date paid', 'Amount', 'Method', 'Reference', 'Note', 'Issue receipt'];

/** A row of one of these sheets from header => value, in the template's order; missing cells are empty. */
function sheetRow(array $header, array $values): array
{
    expect(array_diff(array_keys($values), $header))->toBe([]);

    return array_map(fn (string $column): string => (string) ($values[$column] ?? ''), $header);
}

function balanceRow(array $values): array
{
    return sheetRow(OPENING_BALANCES_HEADER, $values);
}

function utilityRow(array $values): array
{
    return sheetRow(UTILITIES_HEADER, $values);
}

function historyRow(array $values): array
{
    return sheetRow(PAYMENT_HISTORY_HEADER, $values);
}

/**
 * The standard ongoing-rents workbook (P03-13a) with these sheets added
 * (the header is added; null leaves a sheet out). `$sheets` also replaces
 * or removes the standard ones ('Leases' => null …).
 */
function balancesWorkbook(?array $balances = null, ?array $utilities = null, ?array $history = null, array $sheets = []): UploadedFile
{
    return ongoingRentsWorkbook(null, array_merge(array_filter([
        'Opening balances' => $balances !== null ? [OPENING_BALANCES_HEADER, ...$balances] : null,
        'Utilities' => $utilities !== null ? [UTILITIES_HEADER, ...$utilities] : null,
        'Payment history' => $history !== null ? [PAYMENT_HISTORY_HEADER, ...$history] : null,
    ], fn (?array $rows): bool => $rows !== null), $sheets));
}

/** The organization's opening balances (rolled-back ones included), oldest first. */
function openingBalancesOf(Organization $organization): Collection
{
    return inOrganization($organization, fn () => OpeningBalance::query()->withTrashed()->orderBy('id')->get());
}

/** The organization's tenant credit entries as [tenant id, reason, kobo], oldest first. */
function creditLedgerOf(Organization $organization): array
{
    return inOrganization($organization, fn (): array => TenantCreditEntry::query()->orderBy('id')->get()
        ->map(fn (TenantCreditEntry $entry): array => [$entry->tenant_profile_id, $entry->reason->value, $entry->amount_kobo->kobo])->all());
}

/** The dry run's problems of the sheet holding `$target`, as [row, header, letter, severity, message]. */
function sheetIssues(ImportBatch $batch, string $target): array
{
    return portfolioIssues($batch, $target);
}
