<?php

declare(strict_types=1);

use App\Documents\DocumentCounter;
use App\Documents\DocumentNumberer;
use App\Documents\DocumentType;
use App\Documents\NumberingRequiresTransaction;
use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/*
 * P03-04a (P03-04 acceptance: "numbers are gap-free under concurrency"):
 * several PHP processes, each with its own database connection, take
 * numbers from one organization's counters at the same time, in
 * transactions that sometimes roll back. Drill: the processes see only
 * committed rows and the numberer must run outside a test transaction, so
 * this test commits and cleans up after itself.
 */

beforeEach(function () {
    // DatabaseTruncation empties every table between drills, the audit
    // chain's head row too (the migration seeds it); creating the
    // organization writes an audit entry.
    DB::table('audit_chain_head')->insertOrIgnore(['id' => 1, 'last_hash' => null, 'updated_at' => now()]);
    $this->organization = Organization::factory()->create(['name' => 'Adeyemi Realty Ltd']);
});

afterEach(function () {
    app(OrganizationContext::class)->clear();
    DB::table('document_counters')->delete();
    DB::table('organizations')->delete();
    DB::table('activity_log')->delete();
    DB::table('audit_chain_head')->update(['last_hash' => null]);
});

/** Starts tests/Fixtures/Processes/document-numbers.php against this test's database. */
function numberTakingProcess(Organization $organization, int $transactions, int $rollbackEvery, string $barrier): InvokedProcess
{
    $database = DB::connection();

    return Process::path(base_path())->timeout(120)->env([
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => $database->getName(),
        'DB_HOST' => (string) $database->getConfig('host'),
        'DB_PORT' => (string) $database->getConfig('port'),
        'DB_DATABASE' => $database->getDatabaseName(),
        'DB_USERNAME' => (string) $database->getConfig('username'),
        'DB_PASSWORD' => (string) $database->getConfig('password'),
    ])->start([PHP_BINARY, 'tests/Fixtures/Processes/document-numbers.php', (string) $organization->id, (string) $transactions, (string) $rollbackEvery, $barrier]);
}

/** The sequence part of "INV-ADR-2026-000142". */
function sequenceOf(string $number): int
{
    return (int) Str::afterLast($number, '-');
}

it('acceptance: gives gap-free numbers to parallel processes, each on its own connection, rolled-back numbers included', function () {
    $processes = 6;
    $transactions = 25;
    $rollbackEvery = 4;
    $barrier = sys_get_temp_dir().'/goproperty-numbering-'.Str::random(10);
    File::ensureDirectoryExists($barrier);

    try {
        $running = [];

        for ($p = 0; $p < $processes; $p++) {
            $running[] = numberTakingProcess($this->organization, $transactions, $rollbackEvery, $barrier);
        }

        // Every process booted and waiting: start them at once, on a counter
        // that does not exist yet, so they also race to create it.
        $deadline = microtime(true) + 90;

        while (count(File::glob($barrier.'/ready-*')) < $processes) {
            foreach ($running as $process) {
                expect($process->running() || $process->latestErrorOutput() === '')->toBeTrue($process->latestErrorOutput());
            }

            expect(microtime(true))->toBeLessThan($deadline, 'The processes did not boot in time.');
            usleep(10_000);
        }

        expect(DB::table('document_counters')->count())->toBe(0);
        File::put($barrier.'/go', '');

        $lines = [];

        foreach ($running as $process) {
            $result = $process->wait();
            expect($result->successful())->toBeTrue($result->output().$result->errorOutput());

            foreach (array_filter(explode(PHP_EOL, $result->output())) as $line) {
                $lines[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
        }
    } finally {
        File::deleteDirectory($barrier);
    }

    $committed = array_values(array_filter($lines, fn (array $line): bool => $line['committed']));
    $rolledBack = array_values(array_filter($lines, fn (array $line): bool => ! $line['committed']));
    $rolledBackEach = count(array_filter(range(1, $transactions), fn (int $i): bool => $i % $rollbackEvery === 1));
    $expected = $processes * ($transactions - $rolledBackEach);

    expect($lines)->toHaveCount($processes * $transactions)
        ->and(array_unique(array_column($lines, 'pid')))->toHaveCount($processes)
        ->and($committed)->toHaveCount($expected)
        ->and($rolledBack)->toHaveCount($processes * $rolledBackEach);

    // The processes really ran side by side: each started before any ended.
    $starts = array_map(fn (int $pid): float => min(array_column(array_filter($lines, fn (array $line): bool => $line['pid'] === $pid), 'started')), array_unique(array_column($lines, 'pid')));
    $ends = array_map(fn (int $pid): float => max(array_column(array_filter($lines, fn (array $line): bool => $line['pid'] === $pid), 'ended')), array_unique(array_column($lines, 'pid')));
    expect(max($starts))->toBeLessThan(min($ends));

    foreach (['invoice' => 'INV-ADR-', 'receipt' => 'RCT-ADR-'] as $type => $prefix) {
        $numbers = array_column($committed, $type);
        $sequences = array_map(sequenceOf(...), $numbers);
        sort($sequences);

        // Exactly 1..N: no duplicates, no gaps, rolled-back numbers given out again.
        expect($sequences)->toBe(range(1, $expected))
            ->and(array_unique($numbers))->toHaveCount($expected);

        foreach ($numbers as $number) {
            expect($number)->toMatch('/^'.preg_quote($prefix, '/').'\d{4}-\d{6}$/');
        }
    }

    $counters = DB::table('document_counters')->get();

    expect($counters)->toHaveCount(2)
        ->and($counters->pluck('last_seq', 'doc_type')->map(fn ($value): int => (int) $value)->all())->toEqual(['invoice' => $expected, 'receipt' => $expected])
        ->and($counters->pluck('organization_id')->map(fn ($value): int => (int) $value)->unique()->values()->all())->toBe([$this->organization->id]);
});

it('does not use up a number in a rolled-back transaction', function () {
    $numberer = app(DocumentNumberer::class);
    $year = DocumentNumberer::yearOf(now());

    app(OrganizationContext::class)->run($this->organization, function () use ($numberer, $year) {
        expect(DB::transaction(fn () => $numberer->next(DocumentType::Invoice)))->toBe("INV-ADR-{$year}-000001");

        try {
            DB::transaction(function () use ($numberer, $year) {
                expect($numberer->next(DocumentType::Invoice))->toBe("INV-ADR-{$year}-000002");

                throw new DomainException('The bill failed to save.');
            });
        } catch (DomainException) {
        }

        // A year's first number, rolled back: the counter row was created
        // on its own connection and stays, at 0.
        try {
            DB::transaction(function () use ($numberer, $year) {
                expect($numberer->next(DocumentType::Receipt))->toBe("RCT-ADR-{$year}-000001");

                throw new DomainException('The receipt failed to save.');
            });
        } catch (DomainException) {
        }

        expect(DocumentCounter::query()->pluck('last_seq', 'doc_type')->all())->toEqual(['invoice' => 1, 'receipt' => 0]);

        expect(DB::transaction(fn () => $numberer->next(DocumentType::Invoice)))->toBe("INV-ADR-{$year}-000002")
            ->and(DB::transaction(fn () => $numberer->next(DocumentType::Receipt)))->toBe("RCT-ADR-{$year}-000001")
            ->and(DocumentCounter::query()->pluck('last_seq', 'doc_type')->all())->toEqual(['invoice' => 2, 'receipt' => 1]);
    });
});

it('refuses to give a number outside a transaction', function () {
    expect(DB::transactionLevel())->toBe(0);

    app(OrganizationContext::class)->run($this->organization, function () {
        expect(fn () => app(DocumentNumberer::class)->next(DocumentType::Invoice))
            ->toThrow(NumberingRequiresTransaction::class, 'Invoice numbers can only be taken inside the transaction that issues the document.');
    });

    expect(DB::table('document_counters')->count())->toBe(0);
});
