<?php

declare(strict_types=1);

/*
 * One of the processes tests/Drill/BillGenerationDrillTest.php runs side by
 * side, each with its own database connection: it applies payments to one
 * bill through Modules\Billing\Contracts\BillLedger, as Payments will when
 * it confirms them (P03-10a), one transaction per payment, and prints one
 * JSON line per try.
 *
 *   php bill-payments.php <organization id> <bill id> <tries> <kobo> <barrier folder>
 *
 * The process writes `ready-<pid>` in the barrier folder once booted and
 * starts when the test writes `go` there, so all of them start together.
 */

use App\Models\Organization;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Contracts\BillLedger;
use Modules\Billing\Contracts\BillNotPayable;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$organizationId, $billId, $tries, $kobo, $barrier] = [(int) $argv[1], (int) $argv[2], (int) $argv[3], (int) $argv[4], $argv[5]];

$organization = Organization::query()->findOrFail($organizationId);
$ledger = app(BillLedger::class);

file_put_contents($barrier.'/ready-'.getmypid(), '');

$waitUntil = microtime(true) + 60;

while (! file_exists($barrier.'/go')) {
    if (microtime(true) > $waitUntil) {
        fwrite(STDERR, 'The test never said go.'.PHP_EOL);
        exit(1);
    }

    usleep(2_000);
}

app(OrganizationContext::class)->run($organization, function () use ($ledger, $billId, $tries, $kobo): void {
    for ($i = 1; $i <= $tries; $i++) {
        $started = microtime(true);
        $line = ['pid' => getmypid()];

        try {
            $balance = DB::transaction(function () use ($ledger, $billId, $kobo) {
                // Read the balance, decide, then apply: the read must already hold the lock.
                $ledger->lock([$billId]);
                usleep(random_int(0, 1_000));

                return $ledger->apply($billId, Money::kobo($kobo));
            }, 3);
            $line += ['outcome' => 'applied', 'paid' => $balance->paid->kobo];
        } catch (BillNotPayable $refused) {
            $line += ['outcome' => 'refused', 'reason' => $refused->reason];
        } catch (Throwable $failure) {
            $line += ['outcome' => 'error', 'message' => $failure::class.': '.$failure->getMessage()];
        }

        echo json_encode($line + ['started' => $started, 'ended' => microtime(true)], JSON_THROW_ON_ERROR).PHP_EOL;
    }
});
