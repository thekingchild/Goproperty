<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationBankAccount;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Billing\Bills\RentBillGenerator;
use Modules\Billing\Jobs\RenderInvoice;
use Modules\Billing\Models\Bill;
use Modules\Collections\Enums\LineDirection;
use Modules\Collections\Enums\MatchStatus;
use Modules\Collections\Enums\StatementFormat;
use Modules\Collections\Enums\StatementStatus;
use Modules\Collections\Models\BankStatementImport;
use Modules\Collections\Models\BankStatementLine;
use Modules\Leasing\Jobs\RenderLeaseContract;
use Modules\Leasing\Leases\LeaseService;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\PaymentAllocation;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;
use Modules\Tenants\Models\TenantProfile;

/*
 * P03-07b with real concurrency: "a line cannot be matched twice", also
 * when the matcher and owners matching by hand reach the same lines at the
 * same moment. Several PHP processes, each with its own database
 * connection, try to match the same statement lines through
 * Modules\Collections\Matching\LineMatches: the line's row lock, the
 * recorder's idempotency key (statement-line:{id}, unique per
 * organization) and the unique `bank_statement_lines.payment_id` are what
 * keep them apart. Drill: the processes see only committed rows, so this
 * test commits and cleans up after itself.
 */

const STATEMENT_DRILL_TABLES = ['payment_notices', 'notifications', 'jobs', 'receipts', 'payment_allocations', 'tenant_credit_entries', 'tenant_credit_balances', 'payments', 'bank_statement_lines', 'bank_statement_imports', 'organization_bank_accounts', 'bill_items', 'bills', 'document_verifications', 'lease_contracts', 'lease_deposit_entries', 'leases', 'units', 'properties', 'tenant_profiles', 'document_counters', 'settings', 'organizations', 'users', 'activity_log'];

beforeEach(function () {
    Queue::fake([RenderLeaseContract::class, RenderInvoice::class]);
    DB::table('audit_chain_head')->insertOrIgnore(['id' => 1, 'last_hash' => null, 'updated_at' => now()]);
    // Permissions are the policies' tests; this drill is about the locks and keys.
    Gate::before(fn (): bool => true);

    $this->organization = Organization::factory()->create(['name' => 'Adeyemi Realty Ltd']);
    $this->user = User::factory()->create();

    // A tenant with one yearly bill of ₦100,000, and 10 credits of ₦30,000 naming it.
    [$this->tenant, $this->bill, $this->lines] = app(OrganizationContext::class)->run($this->organization, function (): array {
        $tenant = TenantProfile::factory()->create();
        $lease = app(LeaseService::class)->draft($this->user, [
            'tenantId' => $tenant->id,
            'unitId' => Unit::factory()->in(Property::factory()->create())->create()->id,
            'billingCycle' => 'annual',
            'startDate' => CarbonImmutable::now('Africa/Lagos')->toDateString(),
            'endDate' => CarbonImmutable::now('Africa/Lagos')->addYear()->subDay()->toDateString(),
            'rent' => '100,000',
            'deposit' => '0',
        ]);
        app(LeaseService::class)->activate($this->user, $lease);
        app(RentBillGenerator::class)->generate();
        $bill = Bill::query()->sole();

        $account = OrganizationBankAccount::query()->create([
            'bank_code' => '058',
            'bank_name' => 'Guaranty Trust Bank (GTBank)',
            'account_number' => '0123456789',
            'account_number_last4' => '6789',
            'account_fingerprint' => str_repeat('a', 64),
            'account_name' => 'Adeyemi Realty Ltd',
        ]);
        $statement = new BankStatementImport;
        $statement->forceFill([
            'organization_bank_account_id' => $account->id,
            'file_name' => 'drill.csv',
            'format' => StatementFormat::Csv,
            'status' => StatementStatus::Ready,
            'lines_total' => 10,
            'lines_added' => 10,
            'lines_credit' => 10,
        ])->save();

        $lines = [];

        foreach (range(1, 10) as $row) {
            $line = new BankStatementLine;
            $line->forceFill([
                'bank_statement_import_id' => $statement->id,
                'organization_bank_account_id' => $account->id,
                'row_number' => $row,
                'transaction_date' => CarbonImmutable::now('Africa/Lagos')->toDateString(),
                'direction' => LineDirection::Credit,
                'amount_kobo' => Money::naira(30_000),
                'narration' => "RENT PART {$row} ".$bill->payment_reference,
                'line_hash' => hash('sha256', "drill-{$row}"),
            ])->save();
            $lines[] = $line->id;
        }

        return [$tenant, $bill, $lines];
    });
});

afterEach(function () {
    app(OrganizationContext::class)->clear();

    // Payments, receipts and statement lines point at each other: unlink them first.
    DB::table('payments')->update(['receipt_id' => null, 'bank_statement_line_id' => null]);
    DB::table('bank_statement_lines')->update(['payment_id' => null]);

    foreach (STATEMENT_DRILL_TABLES as $table) {
        DB::table($table)->delete();
    }

    DB::table('audit_chain_head')->update(['last_hash' => null]);
});

/** Starts a fixture process (tests/Fixtures/Processes) against this test's database. */
function statementDrillProcess(array $arguments): InvokedProcess
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
        // Receipt PDFs and the tenant's notices wait on the queue: not this drill's business.
        'QUEUE_CONNECTION' => 'database',
    ])->start([PHP_BINARY, 'tests/Fixtures/Processes/statement-matches.php', ...$arguments]);
}

/**
 * Starts the processes, waits until all are ready, says go, and returns
 * every JSON line they printed.
 *
 * @param  list<list<string>>  $runs  each process's arguments, the barrier folder added last
 * @return list<array<string, mixed>>
 */
function matchTogether(array $runs): array
{
    $barrier = sys_get_temp_dir().'/goproperty-matches-'.Str::random(10);
    File::ensureDirectoryExists($barrier);
    $lines = [];

    try {
        $running = array_map(fn (array $arguments): InvokedProcess => statementDrillProcess([...$arguments, $barrier]), $runs);
        $deadline = microtime(true) + 90;

        while (count(File::glob($barrier.'/ready-*')) < count($running)) {
            foreach ($running as $process) {
                expect($process->running() || $process->latestErrorOutput() === '')->toBeTrue($process->latestErrorOutput());
            }

            expect(microtime(true))->toBeLessThan($deadline, 'The processes did not boot in time.');
            usleep(10_000);
        }

        File::put($barrier.'/go', '');

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

    return $lines;
}

it('sub: matches each statement line once when the matcher and owners matching by hand reach it at the same moment', function () {
    $ids = $this->lines;
    $orders = [$ids, array_reverse($ids), [...array_slice($ids, 5), ...array_slice($ids, 0, 5)]];
    $runs = [];

    // 3 processes run the matcher, 3 match by hand, each over all 10 lines in its own order.
    foreach ($orders as $order) {
        $runs[] = [(string) $this->organization->id, (string) $this->user->id, implode(',', $order), 'auto'];
        $runs[] = [(string) $this->organization->id, (string) $this->user->id, implode(',', array_reverse($order)), 'hand:'.$this->tenant->id];
    }

    $tries = matchTogether($runs);
    [$lines, $payments, $allocations, $bill] = app(OrganizationContext::class)->run($this->organization, fn (): array => [
        BankStatementLine::query()->orderBy('id')->get(),
        Payment::query()->orderBy('id')->get(),
        PaymentAllocation::query()->orderBy('id')->get(),
        Bill::query()->findOrFail($this->bill->id),
    ]);
    $byPid = collect($tries)->groupBy('pid');

    // 60 tries, overlapping in time, none failed with an error.
    expect($tries)->toHaveCount(60)
        ->and($byPid->count())->toBe(6)
        ->and($byPid->map(fn ($own) => $own->min('started'))->max())->toBeLessThan($byPid->map(fn ($own) => $own->max('ended'))->min())
        ->and(collect($tries)->where('outcome', 'error')->pluck('message')->all())->toBe([]);

    // Each line: exactly one try matched it, and it has exactly one payment, linked both ways, keyed by the line.
    foreach ($lines as $line) {
        $own = $payments->where('bank_statement_line_id', $line->id);

        expect(collect($tries)->where('line', $line->id)->where('outcome', 'matched'))->toHaveCount(1)
            ->and($own)->toHaveCount(1)
            ->and($line->payment_id)->toBe($own->first()->id)
            ->and($own->first()->idempotency_key)->toBe("statement-line:{$line->id}")
            ->and($line->match_attempts)->toBe(1)
            ->and($line->match_status)->toBe($own->first()->status->value === 'confirmed' ? MatchStatus::Matched : MatchStatus::Suggested);
    }

    // The bill took at most its amount, exactly what the confirmed payments allocated to it.
    expect($payments)->toHaveCount(10)
        ->and($bill->paid_kobo->kobo)->toBe((int) $allocations->sum(fn (PaymentAllocation $allocation): int => $allocation->amount_kobo->kobo))
        ->and($bill->paid_kobo->kobo)->toBeLessThanOrEqual(10_000_000)
        ->and($allocations->pluck('payment_id')->diff($payments->where('status.value', 'confirmed')->pluck('id'))->all())->toBe([]);
});
