<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;

/*
 * P04-01 acceptance "numbering per organization without gaps under
 * concurrency" (docs/plans/P04-subtasks.md default 4: `TKT-000001`, like
 * receipts): several PHP processes, each with its own database
 * connection, raise tickets in the same organization at the same time
 * through TicketService, in transactions that sometimes roll back after
 * the ticket was raised. The committed tickets must hold exactly
 * TKT-000001 … TKT-00000N. Drill: the processes see only committed rows,
 * so this test commits and cleans up after itself.
 */

const TICKET_DRILL_TABLES = ['ticket_status_history', 'tickets', 'units', 'properties', 'document_counters', 'organizations', 'users', 'activity_log'];

beforeEach(function () {
    // DatabaseTruncation empties every table between drills, the audit
    // chain's head row too (the migration seeds it).
    DB::table('audit_chain_head')->insertOrIgnore(['id' => 1, 'last_hash' => null, 'updated_at' => now()]);
    $this->organization = Organization::factory()->create(['name' => 'Adeyemi Realty Ltd']);
    $this->user = User::factory()->create();
    $this->unit = app(OrganizationContext::class)->run($this->organization, fn (): Unit => Unit::factory()->in(Property::factory()->create(['name' => 'Palm Court']))->create(['unit_label' => 'Gatehouse']));
});

afterEach(function () {
    app(OrganizationContext::class)->clear();

    foreach (TICKET_DRILL_TABLES as $table) {
        DB::table($table)->delete();
    }

    DB::table('audit_chain_head')->update(['last_hash' => null]);
});

/** Starts tests/Fixtures/Processes/ticket-raises.php against this test's database. */
function ticketRaisingProcess(Organization $organization, User $user, Unit $unit, int $tickets, int $rollbackEvery, string $barrier): InvokedProcess
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
    ])->start([PHP_BINARY, 'tests/Fixtures/Processes/ticket-raises.php', (string) $organization->id, (string) $user->id, (string) $unit->id, (string) $tickets, (string) $rollbackEvery, $barrier]);
}

it('acceptance: numbers tickets per organization with no gaps when parallel processes raise them, rolled-back ones included', function () {
    $processes = 5;
    $tickets = 12;
    $rollbackEvery = 4;
    $barrier = sys_get_temp_dir().'/goproperty-tickets-'.Str::random(10);
    File::ensureDirectoryExists($barrier);

    try {
        $running = [];

        for ($p = 0; $p < $processes; $p++) {
            $running[] = ticketRaisingProcess($this->organization, $this->user, $this->unit, $tickets, $rollbackEvery, $barrier);
        }

        $deadline = microtime(true) + 90;

        while (count(File::glob($barrier.'/ready-*')) < $processes) {
            foreach ($running as $process) {
                expect($process->running() || $process->latestErrorOutput() === '')->toBeTrue($process->latestErrorOutput());
            }

            expect(microtime(true))->toBeLessThan($deadline, 'The processes did not boot in time.');
            usleep(10_000);
        }

        // No counter yet: the processes also race to create the organization's ticket counter.
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

    $errors = array_values(array_filter($lines, fn (array $line): bool => isset($line['error'])));
    $committed = array_values(array_filter($lines, fn (array $line): bool => ($line['committed'] ?? false) === true));
    $rolledBackEach = count(array_filter(range(1, $tickets), fn (int $i): bool => $i % $rollbackEvery === 1));
    $expected = $processes * ($tickets - $rolledBackEach);

    expect($errors)->toBe([])
        ->and($lines)->toHaveCount($processes * $tickets)
        ->and($committed)->toHaveCount($expected);

    // The processes really ran side by side: each started before any ended.
    $pids = array_unique(array_column($lines, 'pid'));
    $starts = array_map(fn (int $pid): float => min(array_column(array_filter($lines, fn (array $line): bool => $line['pid'] === $pid), 'started')), $pids);
    $ends = array_map(fn (int $pid): float => max(array_column(array_filter($lines, fn (array $line): bool => $line['pid'] === $pid), 'ended')), $pids);
    expect(max($starts))->toBeLessThan(min($ends));

    // The committed tickets hold exactly TKT-000001 … TKT-N, each once; rolled-back numbers were given out again.
    $stored = app(OrganizationContext::class)->run($this->organization, fn (): array => DB::table('tickets')->where('organization_id', $this->organization->id)->orderBy('number')->pluck('number')->all());
    expect($stored)->toBe(array_map(fn (int $sequence): string => 'TKT-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT), range(1, $expected)))
        ->and(array_column($committed, 'number'))->toEqualCanonicalizing($stored)
        ->and((int) DB::table('document_counters')->where('organization_id', $this->organization->id)->where('doc_type', 'ticket')->value('last_seq'))->toBe($expected)
        ->and((int) DB::table('document_counters')->where('doc_type', 'ticket')->value('year'))->toBe(0)
        // One history row per committed ticket, none for the rolled-back ones.
        ->and(DB::table('ticket_status_history')->count())->toBe($expected);
});
