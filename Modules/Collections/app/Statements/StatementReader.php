<?php

declare(strict_types=1);

namespace Modules\Collections\Statements;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Collections\Enums\LineDirection;
use Modules\Collections\Enums\MatchStatus;
use Modules\Collections\Enums\StatementStatus;
use Modules\Collections\Jobs\MatchStatementLines;
use Modules\Collections\Models\BankStatementImport;
use Modules\Collections\Models\BankStatementLine;
use Modules\Import\Contracts\SpreadsheetReader;
use Modules\Import\Contracts\UnreadableSpreadsheet;

/**
 * Reads an uploaded statement's lines into `bank_statement_lines`
 * (Jobs\ProcessStatementImport, on the `imports` queue, inside the
 * statement's organization). Rows after the header go through
 * StatementRowParser; each transaction gets its LineHash and is inserted
 * unless a line with that hash already exists in the organization (the
 * unique key decides, so two uploads at once never add a line twice).
 * Debits are kept as `ignored`: they are never offered for matching.
 *
 * Safe to run again (a retried job): lines this statement already added
 * are found by their hash, and the report's counts are taken from the
 * table at the end, not added up as it goes. The report: transaction rows
 * read, lines added (credits and debits), duplicates skipped, unreadable
 * rows (the first REPORTED with the reason), rows that were not
 * transactions (titles, balances, totals). A file that cannot be read, or
 * with more than MAX_LINES transactions, fails with the reason.
 */
final readonly class StatementReader
{
    public const int MAX_LINES = 20_000;

    public const int REPORTED = 100;

    private const int CHUNK = 500;

    public function __construct(private SpreadsheetReader $reader) {}

    public function process(int $importId): void
    {
        $import = BankStatementImport::query()->find($importId);

        if (! $import instanceof BankStatementImport || $import->status !== StatementStatus::Processing) {
            return;
        }

        $mapping = $import->mapping();
        $media = $import->file();

        if ($mapping === null || $media === null) {
            $this->fail($import, __('The statement file is missing.'));

            return;
        }

        $parser = new StatementRowParser($mapping, CarbonImmutable::now('Africa/Lagos')->startOfDay());
        $occurrences = [];
        $pending = [];
        $read = 0;
        $unreadable = [];
        $unreadableCount = 0;
        $notTransactions = 0;
        $first = null;
        $last = null;

        try {
            foreach ($this->reader->mediaRows($media) as $number => $cells) {
                if ($number <= $mapping->headerRow || self::blank($cells)) {
                    continue;
                }

                try {
                    $line = $parser->parse($number, $cells);
                } catch (UnreadableRow $problem) {
                    $unreadableCount++;

                    if (count($unreadable) < self::REPORTED) {
                        $unreadable[] = ['row' => $number, 'reason' => $problem->getMessage()];
                    }

                    continue;
                }

                if ($line === null) {
                    $notTransactions++;

                    continue;
                }

                if (++$read > self::MAX_LINES) {
                    $this->fail($import, __('The statement has more than :max transactions. Upload it a few months at a time.', ['max' => number_format(self::MAX_LINES)]));

                    return;
                }

                $base = LineHash::base($import->organization_bank_account_id, $line);
                $key = hash('sha256', $base);
                $occurrence = $occurrences[$key] = ($occurrences[$key] ?? 0) + 1;
                $pending[] = $this->row($import, $line, LineHash::of($base, $occurrence), $occurrence);
                $first = $first === null || $line->date->lessThan($first) ? $line->date : $first;
                $last = $last === null || $line->date->greaterThan($last) ? $line->date : $last;

                if (count($pending) >= self::CHUNK) {
                    $this->insert($pending);
                    $pending = [];
                }
            }
        } catch (UnreadableSpreadsheet) {
            $this->fail($import, __('The file could not be read as a spreadsheet. Export the statement from your bank again.'));

            return;
        }

        $this->insert($pending);
        $this->finish($import, $read, $unreadableCount, $unreadable, $notTransactions, $first, $last);
    }

    /**
     * @param  list<array{row: int, reason: string}>  $unreadable
     */
    private function finish(BankStatementImport $import, int $read, int $unreadableCount, array $unreadable, int $notTransactions, ?CarbonImmutable $first, ?CarbonImmutable $last): void
    {
        DB::transaction(function () use ($import, $read, $unreadableCount, $unreadable, $notTransactions, $first, $last): void {
            $locked = BankStatementImport::query()->lockForUpdate()->find($import->id);

            if (! $locked instanceof BankStatementImport || $locked->status !== StatementStatus::Processing) {
                return;
            }

            $added = BankStatementLine::query()->where('bank_statement_import_id', $locked->id)
                ->toBase()->selectRaw('direction, count(*) as line_count')->groupBy('direction')->pluck('line_count', 'direction');
            $credits = (int) ($added[LineDirection::Credit->value] ?? 0);
            $debits = (int) ($added[LineDirection::Debit->value] ?? 0);

            $locked->forceFill([
                'status' => StatementStatus::Ready,
                'period_start' => $first,
                'period_end' => $last,
                'lines_total' => $read,
                'lines_added' => $credits + $debits,
                'lines_credit' => $credits,
                'lines_debit' => $debits,
                'lines_duplicate' => max(0, $read - $credits - $debits),
                'lines_unreadable' => $unreadableCount,
                'report' => ['unreadable' => $unreadable, 'not_transactions' => $notTransactions],
                'processed_at' => CarbonImmutable::now(),
            ])->save();

            activity(StatementImports::LOG)
                ->event('statement.processed')
                ->performedOn($locked)
                ->withProperties([
                    'bank_account_id' => $locked->organization_bank_account_id,
                    'lines_total' => $read,
                    'lines_added' => $credits + $debits,
                    'lines_credit' => $credits,
                    'lines_debit' => $debits,
                    'lines_duplicate' => $locked->lines_duplicate,
                    'lines_unreadable' => $unreadableCount,
                ])
                ->log('Bank statement read');

            // Its credits are matched to bills next (P03-07b), once this commits.
            if ($credits > 0) {
                MatchStatementLines::dispatch($locked->organization_id, $locked->id);
            }
        }, 3);
    }

    /** Marks the statement failed with the reason (the file is kept). */
    public function fail(BankStatementImport $import, string $reason): void
    {
        DB::transaction(function () use ($import, $reason): void {
            $locked = BankStatementImport::query()->lockForUpdate()->find($import->id);

            if (! $locked instanceof BankStatementImport || $locked->status !== StatementStatus::Processing) {
                return;
            }

            $locked->forceFill(['status' => StatementStatus::Failed, 'failure_reason' => mb_substr($reason, 0, 500), 'processed_at' => CarbonImmutable::now()])->save();

            activity(StatementImports::LOG)
                ->event('statement.failed')
                ->performedOn($locked)
                ->withProperties(['bank_account_id' => $locked->organization_bank_account_id])
                ->log('Bank statement could not be read');
        }, 3);
    }

    /** @return array<string, mixed> */
    private function row(BankStatementImport $import, ParsedLine $line, string $hash, int $occurrence): array
    {
        $now = CarbonImmutable::now();

        return [
            'organization_id' => $import->organization_id,
            'bank_statement_import_id' => $import->id,
            'organization_bank_account_id' => $import->organization_bank_account_id,
            'row_number' => $line->rowNumber,
            'transaction_date' => $line->date->format('Y-m-d'),
            'direction' => $line->direction->value,
            'amount_kobo' => $line->amountKobo,
            'narration' => $line->narration,
            'counterparty_name' => $line->counterparty,
            'reference' => $line->reference,
            'balance_kobo' => $line->balanceKobo,
            'line_hash' => $hash,
            'occurrence' => $occurrence,
            // A debit is never a tenant's payment: never offered for matching.
            'match_status' => $line->direction === LineDirection::Debit ? MatchStatus::Ignored->value : MatchStatus::Unmatched->value,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Adds the lines whose hash the organization does not have yet; the
     * unique key (organization, hash) skips the others.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function insert(array $rows): void
    {
        if ($rows !== []) {
            BankStatementLine::query()->insertOrIgnore($rows);
        }
    }

    /** @param  list<mixed>  $cells */
    private static function blank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== null && (! is_string($cell) || trim($cell) !== '')) {
                return false;
            }
        }

        return true;
    }
}
