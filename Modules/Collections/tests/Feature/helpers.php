<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationBankAccount;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Modules\Collections\Models\BankStatementImport;
use Modules\Collections\Models\BankStatementLine;
use Modules\Collections\Statements\StatementImports;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Tests\Support\LivewireHttp;

/*
 * Helpers for the Collections module's tests (P03-07a): bank statements as
 * Nigerian banks export them (a title, the account's name and number and
 * the period above the table; separate Debits and Credits columns and a
 * running balance, or one signed amount column), uploaded through the real
 * writer (Statements\StatementImports), read by the real job (the queue is
 * sync in tests), and pages over HTTP and Livewire updates.
 */

require_once __DIR__.'/../../../../tests/Support/organization-settings.php';
require_once __DIR__.'/../../../../tests/Feature/Exports/helpers.php';

/** The column headings of a GTBank-style export. */
const STATEMENT_HEADER = ['Trans. Date', 'Value Date', 'Reference', 'Debits', 'Credits', 'Balance', 'Remarks'];

/** An organization bank account, its name not checked (no Paystack key in tests). */
function statementAccount(Organization $organization, User $owner, string $number = '0123456789', string $bank = '058'): OrganizationBankAccount
{
    withoutPaystackKey();

    return addBankAccount($organization, $owner, $number, 'Adeyemi Realty Ltd', $bank);
}

/**
 * The rows of a GTBank-style statement of the account: title and account
 * details, the header, then the transactions as
 * [date, reference, debit, credit, balance, remarks].
 *
 * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>  $transactions
 * @return list<list<string>>
 */
function gtStatementRows(array $transactions, string $accountNumber = '0123456789'): array
{
    $rows = [
        ['GUARANTY TRUST BANK PLC'],
        ['Customer Statement of Account'],
        ['Account Name', 'ADEYEMI REALTY LTD'],
        ['Account Number', $accountNumber],
        ['Period', '01-Jan-2026 to 31-Jan-2026'],
        [],
        STATEMENT_HEADER,
    ];

    foreach ($transactions as [$date, $reference, $debit, $credit, $balance, $remarks]) {
        $rows[] = [$date, $date, $reference, $debit, $credit, $balance, $remarks];
    }

    return $rows;
}

/** January's statement: three credits from tenants, two debits. @return list<array{string, string, string, string, string, string}> */
function januaryTransactions(): array
{
    return [
        ['02/01/2026', 'FT26002A1', '', '250,000.00', '1,250,000.00', 'TRF FROM KEMI ADEBAYO GP-7KQ2M9X RENT'],
        ['05/01/2026', 'FT26005B2', '15,000.00', '', '1,235,000.00', 'POS PURCHASE SHOPRITE LEKKI'],
        ['09/01/2026', 'FT26009C3', '', '180,000.50', '1,415,000.50', 'NIP TRF FROM TUNDE BAKARE FLAT 3B'],
        ['15/01/2026', 'FT26015D4', '50,000.00', '', '1,365,000.50', 'SALARY GATEMAN JAN'],
        ['28/01/2026', 'FT26028E5', '', '100,000.00', '1,465,000.50', 'TRF FROM AMAKA OBI GP-3HW8D2T'],
    ];
}

/** A CSV upload with these rows. @param list<list<string|int|float>> $rows */
function statementCsv(array $rows, string $name = 'gtbank-january.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, statementCsvText($rows));
}

/** The text of a CSV file with these rows. @param list<list<string|int|float>> $rows */
function statementCsvText(array $rows): string
{
    $handle = fopen('php://temp', 'w+');

    foreach ($rows as $row) {
        fputcsv($handle, $row, escape: '');
    }

    rewind($handle);

    return (string) stream_get_contents($handle);
}

/** A real .xlsx upload with these rows (numbers and dates stay typed cells). @param list<list<mixed>> $rows */
function statementXlsx(array $rows, string $name = 'gtbank-january.xlsx'): UploadedFile
{
    $base = (string) tempnam(sys_get_temp_dir(), 'gp-statement-test-');
    @unlink($base);
    $writer = SimpleExcelWriter::create($base.'.xlsx', 'xlsx')->noHeaderRow();

    foreach ($rows as $row) {
        $writer->addRow($row === [] ? [''] : $row);
    }

    $writer->close();

    return new UploadedFile($base.'.xlsx', $name, null, null, true);
}

/** Uploads through the service, as the page does (the suggested or saved mapping unless choices are given). */
function uploadStatement(Organization $organization, User $by, OrganizationBankAccount|int|string $account, UploadedFile $file, ?array $choices = null): BankStatementImport
{
    return inOrganization($organization, fn (): BankStatementImport => app(StatementImports::class)->upload(
        $by,
        $account instanceof OrganizationBankAccount ? $account->id : $account,
        $file,
        $choices,
    ));
}

/** The statement as stored now. */
function storedStatement(Organization $organization, BankStatementImport|int $statement): BankStatementImport
{
    return inOrganization($organization, fn (): BankStatementImport => BankStatementImport::query()->findOrFail($statement instanceof BankStatementImport ? $statement->id : $statement));
}

/** Every statement line of the organization, in the order they were added. */
function statementLinesOf(Organization $organization): Collection
{
    return inOrganization($organization, fn () => BankStatementLine::query()->orderBy('id')->get());
}

/**
 * The lines as [date, direction, kobo, narration] rows, oldest first.
 *
 * @return list<array{string, string, int, string}>
 */
function statementLineSummary(Organization $organization): array
{
    return statementLinesOf($organization)
        ->sortBy(fn (BankStatementLine $line): string => $line->transaction_date->format('Y-m-d').sprintf('%08d', $line->id))
        ->map(fn (BankStatementLine $line): array => [$line->transaction_date->format('Y-m-d'), $line->direction->value, $line->amount_kobo->kobo, $line->narration])
        ->values()->all();
}

/** The report counts of a statement, by name. @return array<string, int> */
function statementCounts(Organization $organization, BankStatementImport $statement): array
{
    $stored = storedStatement($organization, $statement);

    return [
        'read' => $stored->lines_total,
        'added' => $stored->lines_added,
        'credits' => $stored->lines_credit,
        'debits' => $stored->lines_debit,
        'duplicates' => $stored->lines_duplicate,
        'unreadable' => $stored->lines_unreadable,
    ];
}

/** A page of the statements area, as the user ($path after /statements). */
function statementsPage(User $user, string $path = '', string $slug = 'acme-homes'): TestResponse
{
    exportsFreshRequest();

    return test()->actingAs($user)->get("/o/{$slug}/statements{$path}");
}

/** A Livewire update on a statements page's snapshot, as a fresh request by the same user. */
function statementsUpdate(User $user, string $snapshot, array $updates = [], array $calls = []): TestResponse
{
    exportsFreshRequest();
    test()->actingAs($user);

    return LivewireHttp::update(test(), $snapshot, $updates, $calls);
}

/** The line ids a rendered statement page lists, in order. @return list<int> */
function listedStatementLines(TestResponse|string $page): array
{
    preg_match_all('/data-line="(\d+)"/', htmlOf($page), $matches);

    return array_map('intval', $matches[1]);
}

/** The statement ids a rendered statements list shows, in order. @return list<int> */
function listedStatements(TestResponse|string $page): array
{
    preg_match_all('/data-statement="(\d+)"/', htmlOf($page), $matches);

    return array_map('intval', $matches[1]);
}
