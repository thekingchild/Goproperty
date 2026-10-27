<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Media\EvidenceFileProtected;
use App\Media\Media;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Collections\Enums\DateOrder;
use Modules\Collections\Enums\LineDirection;
use Modules\Collections\Enums\MatchStatus;
use Modules\Collections\Enums\StatementFormat;
use Modules\Collections\Enums\StatementStatus;
use Modules\Collections\Jobs\ProcessStatementImport;
use Modules\Collections\Models\BankStatementColumnMapping;
use Modules\Collections\Models\BankStatementImport;
use Modules\Collections\Models\BankStatementLine;
use Modules\Collections\Statements\LineHash;
use Modules\Collections\Statements\StatementReader;

require_once __DIR__.'/helpers.php';

/*
 * P03-07a: bank statement import (ONBOARDING_COMPLIANCE.md § 4.1;
 * DATABASE_SCHEMA.md § 4 `bank_statement_imports`, `bank_statement_lines`).
 * The same statement uploaded again, or an overlapping one, adds no line
 * twice (`line_hash`, unique per organization), while two genuinely
 * identical credits of one day stay two lines; debits are kept but never
 * offered for matching; amounts are exact kobo; the report counts what
 * happened; the column mapping is saved per bank account.
 */

beforeEach(function () {
    Storage::fake('media');
    seedOrganizationRoles();
    $this->travelTo('2026-02-03 10:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner']), $this->acme);
    $this->account = statementAccount($this->acme, $this->owner);
});

afterEach(fn () => exportsFreshRequest());

it('reads a GTBank-style statement: credits to match, debits kept but ignored, the report counted', function () {
    $statement = uploadStatement($this->acme, $this->owner, $this->account, statementCsv(gtStatementRows(januaryTransactions())));
    $stored = storedStatement($this->acme, $statement);

    expect($stored->status)->toBe(StatementStatus::Ready)
        ->and($stored->format)->toBe(StatementFormat::Csv)
        ->and($stored->file_name)->toBe('gtbank-january.csv')
        ->and($stored->period_start?->toDateString())->toBe('2026-01-02')
        ->and($stored->period_end?->toDateString())->toBe('2026-01-28')
        ->and(statementCounts($this->acme, $statement))->toEqual(['read' => 5, 'added' => 5, 'credits' => 3, 'debits' => 2, 'duplicates' => 0, 'unreadable' => 0])
        ->and(statementLineSummary($this->acme))->toBe([
            ['2026-01-02', 'credit', 25_000_000, 'TRF FROM KEMI ADEBAYO GP-7KQ2M9X RENT'],
            ['2026-01-05', 'debit', 1_500_000, 'POS PURCHASE SHOPRITE LEKKI'],
            ['2026-01-09', 'credit', 18_000_050, 'NIP TRF FROM TUNDE BAKARE FLAT 3B'],
            ['2026-01-15', 'debit', 5_000_000, 'SALARY GATEMAN JAN'],
            ['2026-01-28', 'credit', 10_000_000, 'TRF FROM AMAKA OBI GP-3HW8D2T'],
        ]);

    $first = statementLinesOf($this->acme)->first();
    expect($first->reference)->toBe('FT26002A1')
        ->and($first->balance_kobo?->kobo)->toBe(125_000_000)
        ->and($first->row_number)->toBe(8)
        ->and($first->occurrence)->toBe(1)
        ->and($first->organization_bank_account_id)->toBe($this->account->id)
        ->and($first->line_hash)->toHaveLength(64);

    // The file is kept as evidence in the statements collection.
    $file = inOrganization($this->acme, fn () => storedStatement($this->acme, $statement)->file());
    expect($file)->toBeInstanceOf(Media::class)
        ->and($file->collection_name)->toBe('statements')
        ->and($file->mime_type)->toBe('text/csv')
        ->and($stored->media_id)->toBe($file->id);

    // Audited: uploaded by the owner, read by the system; counts only.
    $uploaded = AuditEntry::query()->where('event', 'statement.uploaded')->sole();
    $processed = AuditEntry::query()->where('event', 'statement.processed')->sole();
    expect($uploaded->causer_id)->toBe($this->owner->id)
        ->and($processed->properties['lines_added'])->toBe(5)
        ->and($processed->properties['lines_duplicate'])->toBe(0)
        ->and(json_encode($processed->properties))->not->toContain('KEMI');
});

it('acceptance: re-uploading the same statement creates no duplicate lines', function () {
    $first = uploadStatement($this->acme, $this->owner, $this->account, statementCsv(gtStatementRows(januaryTransactions())));
    $hashes = statementLinesOf($this->acme)->pluck('line_hash')->all();

    $again = uploadStatement($this->acme, $this->owner, $this->account, statementCsv(gtStatementRows(januaryTransactions()), 'gtbank-january (1).csv'));
    // The same statement exported as Excel instead of CSV, numbers and dates typed: still the same lines.
    $asExcel = uploadStatement($this->acme, $this->owner, $this->account, statementXlsx(gtStatementRows(array_map(
        fn (array $line): array => [$line[0], $line[1], $line[2], $line[3], $line[4], strtolower($line[5])],
        januaryTransactions(),
    ))));

    expect(statementLinesOf($this->acme))->toHaveCount(5)
        ->and(statementLinesOf($this->acme)->pluck('line_hash')->all())->toBe($hashes)
        ->and(statementLinesOf($this->acme)->pluck('bank_statement_import_id')->unique()->all())->toBe([$first->id])
        ->and(statementCounts($this->acme, $again))->toEqual(['read' => 5, 'added' => 0, 'credits' => 0, 'debits' => 0, 'duplicates' => 5, 'unreadable' => 0])
        ->and(statementCounts($this->acme, $asExcel))->toEqual(['read' => 5, 'added' => 0, 'credits' => 0, 'debits' => 0, 'duplicates' => 5, 'unreadable' => 0])
        ->and(storedStatement($this->acme, $again)->status)->toBe(StatementStatus::Ready);

    // Both uploads are kept (evidence), and the page of the second says nothing new came in.
    expect(inOrganization($this->acme, fn () => Media::query()->where('collection_name', 'statements')->count()))->toBe(3);
    statementsPage($this->owner, "/{$again->id}")->assertOk()
        ->assertSeeInOrder(['New lines added', '0', 'Duplicates skipped', '5'])
        ->assertSee('No lines from this statement');
});

it('acceptance: an overlapping statement adds only the new lines', function () {
    uploadStatement($this->acme, $this->owner, $this->account, statementCsv(gtStatementRows(januaryTransactions())));

    // 15 January to 10 February: the last two January lines again, then three new ones.
    $overlap = uploadStatement($this->acme, $this->owner, $this->account, statementCsv(gtStatementRows([
        ['15/01/2026', 'FT26015D4', '50,000.00', '', '1,365,000.50', 'SALARY GATEMAN JAN'],
        ['28/01/2026', 'FT26028E5', '', '100,000.00', '1,465,000.50', 'TRF FROM AMAKA OBI GP-3HW8D2T'],
        ['01/02/2026', 'FT26032F6', '', '250,000.00', '1,715,000.50', 'TRF FROM KEMI ADEBAYO GP-9ZT4K2M RENT'],
        ['02/02/2026', 'FT26033G7', '5,000.00', '', '1,710,000.50', 'SMS ALERT CHARGES'],
        ['02/02/2026', 'FT26033H8', '', '75,000.00', '1,785,000.50', 'TRF FROM CHIDI OKAFOR'],
    ]), 'gtbank-jan15-feb10.csv'));

    expect(statementCounts($this->acme, $overlap))->toEqual(['read' => 5, 'added' => 3, 'credits' => 2, 'debits' => 1, 'duplicates' => 2, 'unreadable' => 0])
        ->and(statementLinesOf($this->acme))->toHaveCount(8)
        ->and(storedStatement($this->acme, $overlap)->period_start?->toDateString())->toBe('2026-01-15')
        ->and(inOrganization($this->acme, fn () => BankStatementLine::query()->where('bank_statement_import_id', $overlap->id)->orderBy('row_number')->pluck('narration')->all()))
        ->toBe(['TRF FROM KEMI ADEBAYO GP-9ZT4K2M RENT', 'SMS ALERT CHARGES', 'TRF FROM CHIDI OKAFOR']);
});

it('keeps two genuinely identical credits of the same day as two lines, and still finds them again in a re-upload', function (bool $withBalance) {
    $rows = fn (array $lines): array => array_merge([['Date', 'Description', 'Debit', 'Credit', ...($withBalance ? ['Balance'] : [])]], array_map(
        fn (array $line): array => $withBalance ? $line : array_slice($line, 0, 4),
        $lines,
    ));
    // The same tenant paid the same rent twice on 10 January (two flats).
    $january = [
        ['10/01/2026', 'TRF FROM KEMI ADEBAYO RENT', '', '100,000.00', '600,000.00'],
        ['10/01/2026', 'TRF FROM KEMI ADEBAYO RENT', '', '100,000.00', '700,000.00'],
        ['11/01/2026', 'BANK CHARGES', '50.00', '', '699,950.00'],
    ];

    $first = uploadStatement($this->acme, $this->owner, $this->account, statementCsv($rows($january), 'first.csv'));
    $again = uploadStatement($this->acme, $this->owner, $this->account, statementCsv($rows($january), 'again.csv'));
    // A later statement repeating 10–11 January and adding a third identical credit on 12 January.
    $later = uploadStatement($this->acme, $this->owner, $this->account, statementCsv($rows([...$january, ['12/01/2026', 'TRF FROM KEMI ADEBAYO RENT', '', '100,000.00', '799,950.00']]), 'later.csv'));

    $credits = statementLinesOf($this->acme)->where('direction', LineDirection::Credit)->values();
    expect(statementCounts($this->acme, $first))->toEqual(['read' => 3, 'added' => 3, 'credits' => 2, 'debits' => 1, 'duplicates' => 0, 'unreadable' => 0])
        ->and(statementCounts($this->acme, $again)['duplicates'])->toBe(3)
        ->and(statementCounts($this->acme, $later))->toEqual(['read' => 4, 'added' => 1, 'credits' => 1, 'debits' => 0, 'duplicates' => 3, 'unreadable' => 0])
        ->and($credits)->toHaveCount(3)
        ->and($credits->pluck('line_hash')->unique())->toHaveCount(3)
        ->and($credits->pluck('occurrence')->all())->toBe($withBalance ? [1, 1, 1] : [1, 2, 1]);
})->with([
    'with a running balance' => [true],
    'without a balance or reference (occurrence tells them apart)' => [false],
]);

it('defines the line hash from the account, date, direction, amount, narration, balance and reference', function () {
    $statement = uploadStatement($this->acme, $this->owner, $this->account, statementCsv(gtStatementRows(januaryTransactions())));
    $line = statementLinesOf($this->acme)->first();

    $expected = hash('sha256', implode("\x1F", ['v1', (string) $this->account->id, '2026-01-02', 'credit', '25000000', 'trf from kemi adebayo gp 7kq2m9x rent', '125000000', 'ft26002a1'])."\x1F1");
    expect($line->line_hash)->toBe($expected)
        // Narrations differing only in case, spaces and punctuation are the same transaction.
        ->and(LineHash::normalise('  TRF/From Kemi-Adebayo,  GP-7KQ2M9X  Rent '))->toBe('trf from kemi adebayo gp 7kq2m9x rent');

    // The same statement of another account of the organization is other money: its lines are added.
    $other = statementAccount($this->acme, $this->owner, '0987654321', '044');
    $second = uploadStatement($this->acme, $this->owner, $other, statementCsv(gtStatementRows(januaryTransactions(), '0987654321')));
    expect(statementCounts($this->acme, $second)['added'])->toBe(5)
        ->and(statementLinesOf($this->acme))->toHaveCount(10)
        ->and(storedStatement($this->acme, $statement)->lines_added)->toBe(5);
});

it('keeps each organization\'s lines apart: the same statement in two organizations is two sets of lines', function () {
    $other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Bello Homes']);
    $otherOwner = joinAs(User::factory()->create(), $other);
    $otherAccount = statementAccount($other, $otherOwner);

    uploadStatement($this->acme, $this->owner, $this->account, statementCsv(gtStatementRows(januaryTransactions())));
    $theirs = uploadStatement($other, $otherOwner, $otherAccount, statementCsv(gtStatementRows(januaryTransactions())));

    expect(statementCounts($other, $theirs)['added'])->toBe(5)
        ->and(statementLinesOf($this->acme))->toHaveCount(5)
        ->and(statementLinesOf($other))->toHaveCount(5)
        ->and(statementLinesOf($this->acme)->pluck('organization_id')->unique()->all())->toBe([$this->acme->id]);
});

it('sub: debit lines are never offered for matching', function () {
    uploadStatement($this->acme, $this->owner, $this->account, statementCsv(gtStatementRows(januaryTransactions())));
    $lines = statementLinesOf($this->acme);
    $debits = $lines->where('direction', LineDirection::Debit);

    expect($debits)->toHaveCount(2)
        ->and($debits->every(fn (BankStatementLine $line): bool => $line->match_status === MatchStatus::Ignored && ! $line->isOfferedForMatching()))->toBeTrue()
        ->and($lines->where('direction', LineDirection::Credit)->every(fn (BankStatementLine $line): bool => $line->match_status === MatchStatus::Unmatched && $line->isOfferedForMatching()))->toBeTrue()
        // What matching (P03-07b) works on: the credits only.
        ->and(inOrganization($this->acme, fn () => BankStatementLine::query()->offeredForMatching()->orderBy('id')->pluck('narration')->all()))
        ->toBe(['TRF FROM KEMI ADEBAYO GP-7KQ2M9X RENT', 'NIP TRF FROM TUNDE BAKARE FLAT 3B', 'TRF FROM AMAKA OBI GP-3HW8D2T']);

    // No writer can make a debit a candidate: the model refuses.
    $debit = $debits->first();
    foreach ([MatchStatus::Unmatched, MatchStatus::Suggested, MatchStatus::Matched] as $status) {
        expect(fn () => inOrganization($this->acme, fn () => BankStatementLine::query()->findOrFail($debit->id)->forceFill(['match_status' => $status])->save()))
            ->toThrow(LogicException::class, 'never offered for matching');
    }

    // The page marks credits to match and nothing else; its "Credits to match" view lists no debit.
    $page = statementsPage($this->owner, '/'.$debit->bank_statement_import_id)->assertOk();
    preg_match_all('/data-line="(\d+)" data-direction="(\w+)"(\s+data-candidate)?/', (string) $page->getContent(), $marks, PREG_SET_ORDER);
    expect(collect($marks)->mapWithKeys(fn (array $mark): array => [$mark[1] => [$mark[2], isset($mark[3])]])->all())
        ->toEqual($lines->mapWithKeys(fn (BankStatementLine $line): array => [$line->id => [$line->direction->value, $line->direction === LineDirection::Credit]])->all());
    $candidates = statementsPage($this->owner, '/'.$debit->bank_statement_import_id.'?direction=credit&status=unmatched')->assertOk();
    expect(listedStatementLines($candidates))->toBe($lines->where('direction', LineDirection::Credit)->pluck('id')->values()->all());
});

it('reads the common Nigerian export shapes: one signed amount column, an amount with a Dr/Cr column, other date formats', function () {
    $signed = statementCsv([
        ['Zenith Bank Plc - Statement of Account'],
        ['Account No: 0123456789'],
        ['Posting Date', 'Description', 'Amount', 'Balance'],
        ['2026-01-03 09:12:44', 'NIP/FROM IFEOMA NWOSU/GP-4DR7XQ2', '120,000.00', '1,120,000.00'],
        ['2026-01-04 14:00:00', 'ELECTRICITY TOKEN', '-20,000.00', '1,100,000.00'],
        ['2026-01-06 08:30:10', 'REVERSAL (CHARGES)', '(500.00)', '1,099,500.00'],
    ], 'zenith.csv');
    $drcr = statementCsv([
        ['Tran Date', 'Narration', 'Amount', 'Dr/Cr', 'Ref No'],
        ['07-Jan-2026', 'TRANSFER FROM SEGUN ALABI', '₦45,000.00', 'CR', 'S1234'],
        ['08 Jan 2026', 'CASH WITHDRAWAL', 'NGN 10,000', 'DR', 'S1235'],
        ['09JAN26', 'TRANSFER FROM BISI ADE', 'N 7,500.25', 'C', 'S1236'],
    ], 'access.csv');

    $first = uploadStatement($this->acme, $this->owner, $this->account, $signed);
    $second = uploadStatement($this->acme, $this->owner, $this->account, $drcr);

    expect(statementCounts($this->acme, $first))->toEqual(['read' => 3, 'added' => 3, 'credits' => 1, 'debits' => 2, 'duplicates' => 0, 'unreadable' => 0])
        ->and(statementCounts($this->acme, $second))->toEqual(['read' => 3, 'added' => 3, 'credits' => 2, 'debits' => 1, 'duplicates' => 0, 'unreadable' => 0])
        ->and(statementLineSummary($this->acme))->toBe([
            ['2026-01-03', 'credit', 12_000_000, 'NIP/FROM IFEOMA NWOSU/GP-4DR7XQ2'],
            ['2026-01-04', 'debit', 2_000_000, 'ELECTRICITY TOKEN'],
            ['2026-01-06', 'debit', 50_000, 'REVERSAL (CHARGES)'],
            ['2026-01-07', 'credit', 4_500_000, 'TRANSFER FROM SEGUN ALABI'],
            ['2026-01-08', 'debit', 1_000_000, 'CASH WITHDRAWAL'],
            ['2026-01-09', 'credit', 750_025, 'TRANSFER FROM BISI ADE'],
        ])
        ->and(statementLinesOf($this->acme)->firstWhere('narration', 'TRANSFER FROM SEGUN ALABI')?->reference)->toBe('S1234');
});

it('reads Excel statements with typed date and number cells', function () {
    $file = statementXlsx([
        ['First Bank of Nigeria'],
        ['Account Number', 123456789],
        ['Date', 'Narrative', 'Money Out', 'Money In', 'Running Balance'],
        [new DateTimeImmutable('2026-01-12 00:00:00'), 'TRF FROM OLU', null, 150000.07, 1150000.07],
        [new DateTimeImmutable('2026-01-13 00:00:00'), 'ATM WDL', 2500, null, 1147500.07],
        [46037, 'TRF FROM OLU AGAIN', null, 0.29, 1147500.36],
    ], 'firstbank.xlsx');

    $statement = uploadStatement($this->acme, $this->owner, $this->account, $file);

    expect(storedStatement($this->acme, $statement)->format)->toBe(StatementFormat::Xlsx)
        ->and(statementCounts($this->acme, $statement))->toEqual(['read' => 3, 'added' => 3, 'credits' => 2, 'debits' => 1, 'duplicates' => 0, 'unreadable' => 0])
        ->and(statementLineSummary($this->acme))->toBe([
            ['2026-01-12', 'credit', 15_000_007, 'TRF FROM OLU'],
            ['2026-01-13', 'debit', 250_000, 'ATM WDL'],
            ['2026-01-15', 'credit', 29, 'TRF FROM OLU AGAIN'],
        ])
        ->and(statementLinesOf($this->acme)->last()->balance_kobo?->kobo)->toBe(114_750_036);
});

it('parses amounts to exact kobo, never through floats', function (string $cell, int $kobo) {
    $statement = uploadStatement($this->acme, $this->owner, $this->account, statementCsv([
        ['Date', 'Narration', 'Debit', 'Credit'],
        ['20/01/2026', 'TRF FROM KEMI', '', $cell],
    ], 'amount.csv'));

    expect(storedStatement($this->acme, $statement)->lines_added)->toBe(1)
        ->and(statementLinesOf($this->acme)->sole()->amount_kobo->kobo)->toBe($kobo);
})->with([
    // 0.29 × 100 and 1.15 × 100 are 28.999… and 114.999… as floats.
    'kobo a float would lose (0.29)' => ['0.29', 29],
    'kobo a float would lose (1.15)' => ['1.15', 115],
    'thousands commas' => ['1,234,567.89', 123_456_789],
    'naira sign and spaces' => ['₦ 50,000.10', 5_000_010],
    'NGN prefix' => ['NGN 1,000', 100_000],
    'N prefix' => ['N2,500.5', 250_050],
    'a CR mark' => ['5,000.00 CR', 500_000],
    'a large annual rent' => ['12,345,678,901.23', 1_234_567_890_123],
    'beyond float precision' => ['90,071,992,547,409.93', 9_007_199_254_740_993],
]);

it('lists unreadable rows with the reason and keeps the readable ones', function () {
    $statement = uploadStatement($this->acme, $this->owner, $this->account, statementCsv([
        ['Statement of account'],
        ['Date', 'Narration', 'Debit', 'Credit', 'Balance'],
        ['Opening Balance', '', '', '', '1,000,000.00'],
        ['02/01/2026', 'TRF FROM KEMI', '', '100,000.00', '1,100,000.00'],
        ['03/01/2026', 'TRF FROM TUNDE', '', '1,234.567', '1,101,234.57'],
        ['04/01/2026', 'ODD ROW', '5,000.00', '6,000.00', '1,102,234.57'],
        ['31/02/2026', 'BAD DATE', '', '7,000.00', '1,109,234.57'],
        ['', 'NO DATE', '', '8,000.00', '1,117,234.57'],
        ['05/01/2026', 'BAD AMOUNT', '', 'twelve naira', '1,117,234.57'],
        ['06/01/2026', 'NEGATIVE CREDIT', '', '-500.00', '1,116,734.57'],
        ['09/03/2026', 'FUTURE', '', '9,000.00', '1,125,734.57'],
        ['07/01/2026', 'TRF FROM AMAKA', '', '50,000.00', '1,166,734.57'],
        [],
        ['', 'Total', '5,000.00', '1,193,234.57', ''],
        ['Closing Balance', '', '', '', '1,166,734.57'],
    ], 'messy.csv'));
    $stored = storedStatement($this->acme, $statement);

    expect(statementCounts($this->acme, $statement))->toEqual(['read' => 2, 'added' => 2, 'credits' => 2, 'debits' => 0, 'duplicates' => 0, 'unreadable' => 7])
        ->and($stored->unreadableRows())->toEqual([
            ['row' => 5, 'reason' => 'The credit has more than two decimals (kobo).'],
            ['row' => 6, 'reason' => 'The row has both a credit and a debit.'],
            ['row' => 7, 'reason' => '"31/02/2026" is not a date.'],
            ['row' => 8, 'reason' => 'The row has no date.'],
            ['row' => 9, 'reason' => 'The credit is not an amount in naira, such as 150,000.00.'],
            ['row' => 10, 'reason' => 'The credit is a negative amount.'],
            ['row' => 11, 'reason' => 'The date 09/03/2026 is in the future.'],
        ])
        ->and($stored->report['not_transactions'] ?? null)->toBe(3)
        ->and(statementLinesOf($this->acme)->pluck('narration')->all())->toBe(['TRF FROM KEMI', 'TRF FROM AMAKA']);

    statementsPage($this->owner, "/{$statement->id}")->assertOk()
        ->assertSee('7 rows could not be read')
        ->assertSee('Row 7: &quot;31/02/2026&quot; is not a date.', false)
        ->assertSee('Row 11: The date 09/03/2026 is in the future.');
});

it('saves the column mapping per bank account and maps the account\'s next statement with it', function () {
    // Headings no alias knows: the member maps them on the page.
    $rows = fn (string $credit): array => [
        ['Txn Day', 'Story', 'Out', 'In', 'Left'],
        ['01/13/2026', 'TRF FROM KEMI', '', $credit, '1,100,000.00'],
        ['01/14/2026', 'CHARGES', '100.00', '', '1,099,900.00'],
    ];
    $choices = ['columns' => ['date' => '0', 'narration' => '1', 'debit' => '2', 'credit' => '3', 'balance' => '4'], 'date_order' => 'mdy'];

    expect(fn () => uploadStatement($this->acme, $this->owner, $this->account, statementCsv($rows('100,000.00'))))
        ->toThrow(ValidationException::class);

    $first = uploadStatement($this->acme, $this->owner, $this->account, statementCsv($rows('100,000.00')), $choices);
    $saved = inOrganization($this->acme, fn () => BankStatementColumnMapping::query()->sole());

    expect(statementCounts($this->acme, $first)['added'])->toBe(2)
        ->and(statementLineSummary($this->acme)[0])->toBe(['2026-01-13', 'credit', 10_000_000, 'TRF FROM KEMI'])
        ->and($saved->organization_bank_account_id)->toBe($this->account->id)
        ->and($saved->columns)->toEqual(['date' => 'Txn Day', 'narration' => 'Story', 'debit' => 'Out', 'credit' => 'In', 'balance' => 'Left'])
        ->and($saved->date_order)->toBe(DateOrder::MonthFirst)
        ->and($saved->saved_by_user_id)->toBe($this->owner->id);

    // The next statement of the account maps itself (no choices given), month first.
    $next = uploadStatement($this->acme, $this->owner, $this->account, statementCsv([
        ['Txn Day', 'Story', 'Out', 'In', 'Left'],
        ['02/01/2026', 'TRF FROM TUNDE', '', '80,000.00', '1,179,900.00'],
    ]));
    expect(statementCounts($this->acme, $next)['added'])->toBe(1)
        ->and(statementLineSummary($this->acme)[2])->toBe(['2026-02-01', 'credit', 8_000_000, 'TRF FROM TUNDE']);

    // Another account of the organization does not get this account's mapping.
    $other = statementAccount($this->acme, $this->owner, '0987654321', '044');
    expect(fn () => uploadStatement($this->acme, $this->owner, $other, statementCsv($rows('5,000.00'))))->toThrow(ValidationException::class)
        ->and(inOrganization($this->acme, fn () => BankStatementColumnMapping::query()->count()))->toBe(1);
});

it('refuses a mapping that cannot read the statement, naming the field', function (array $columns, string $field, string $message) {
    expect(fn () => uploadStatement($this->acme, $this->owner, $this->account, statementCsv(gtStatementRows(januaryTransactions())), ['columns' => $columns, 'date_order' => 'dmy']))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey("columns.{$field}")->and($e->errors()["columns.{$field}"][0])->toContain($message));

    expect(inOrganization($this->acme, fn () => BankStatementImport::query()->count()))->toBe(0)
        ->and(Storage::disk('media')->allFiles())->toBe([]);
})->with([
    'no date column' => [['narration' => '6', 'debit' => '3', 'credit' => '4'], 'date', 'Choose the column that holds the transaction date'],
    'no money column' => [['date' => '0', 'narration' => '6'], 'credit', 'Choose the credit and debit columns, or the one amount column'],
    'both kinds of money column' => [['date' => '0', 'narration' => '6', 'credit' => '4', 'amount' => '3'], 'amount', 'not both'],
    'a column used twice' => [['date' => '0', 'narration' => '0', 'credit' => '4'], 'narration', 'already used'],
    'Dr/Cr without an amount' => [['date' => '0', 'narration' => '6', 'credit' => '4', 'direction' => '2'], 'direction', 'only goes with one amount column'],
]);

it('reads the lines on the imports queue, once, and a retried job adds nothing twice', function () {
    Queue::fake();
    $statement = uploadStatement($this->acme, $this->owner, $this->account, statementCsv(gtStatementRows(januaryTransactions())));

    Queue::assertPushedOn('imports', ProcessStatementImport::class, fn (ProcessStatementImport $job): bool => $job->importId === $statement->id && $job->timeout === 50);
    expect(storedStatement($this->acme, $statement)->status)->toBe(StatementStatus::Processing)
        ->and(statementLinesOf($this->acme))->toHaveCount(0);
    statementsPage($this->owner, "/{$statement->id}")->assertOk()->assertSee('The statement is being read')->assertSee('wire:poll', false);

    // A first try that stopped after inserting some lines, then the retry.
    inOrganization($this->acme, function () use ($statement): void {
        $reader = app(StatementReader::class);
        $reader->process($statement->id);
        BankStatementImport::query()->whereKey($statement->id)->update(['status' => 'processing']);
        $reader->process($statement->id);
        $reader->process($statement->id);
    });

    expect(statementLinesOf($this->acme))->toHaveCount(5)
        ->and(statementCounts($this->acme, $statement))->toEqual(['read' => 5, 'added' => 5, 'credits' => 3, 'debits' => 2, 'duplicates' => 0, 'unreadable' => 0]);
});

it('marks a statement failed, with the reason, when its file cannot be read, and keeps the file', function () {
    Queue::fake();
    $statement = uploadStatement($this->acme, $this->owner, $this->account, statementCsv(gtStatementRows(januaryTransactions())));
    $file = inOrganization($this->acme, fn () => storedStatement($this->acme, $statement)->file());
    Storage::disk('media')->delete($file->getPathRelativeToRoot());

    inOrganization($this->acme, fn () => app(StatementReader::class)->process($statement->id));

    expect(storedStatement($this->acme, $statement)->status)->toBe(StatementStatus::Failed)
        ->and(storedStatement($this->acme, $statement)->failure_reason)->toContain('could not be read')
        ->and(statementLinesOf($this->acme))->toHaveCount(0);
    statementsPage($this->owner, "/{$statement->id}")->assertOk()->assertSee('could not be read');
});

it('never deletes a statement, its file or its lines', function () {
    $statement = uploadStatement($this->acme, $this->owner, $this->account, statementCsv(gtStatementRows(januaryTransactions())));

    inOrganization($this->acme, function () use ($statement): void {
        expect(fn () => storedStatement($this->acme, $statement)->delete())->toThrow(LogicException::class, 'never deleted')
            ->and(fn () => BankStatementLine::query()->first()->delete())->toThrow(LogicException::class, 'never deleted')
            ->and(fn () => storedStatement($this->acme, $statement)->file()?->delete())->toThrow(EvidenceFileProtected::class);
    });

    expect(statementLinesOf($this->acme))->toHaveCount(5)
        ->and(Storage::disk('media')->allFiles())->toHaveCount(1);
});
