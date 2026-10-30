<?php

declare(strict_types=1);

/*
 * One of the processes tests/Drill/MeteringDrillTest.php runs side by side,
 * each with its own database connection (P06-02): it runs metering for one
 * organization several times, as the hourly job, the listener on a
 * subscription becoming active and a retried job could at once, and prints
 * one JSON line per run with the invoice it made (null: nothing to make).
 *
 *   php metering-runs.php <organization id> <runs> <barrier folder>
 *
 * The process writes `ready-<pid>` in the barrier folder once booted and
 * starts when the test writes `go` there.
 */

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\Console\Kernel;
use Modules\SaasBilling\Jobs\MeterUnits;
use Modules\SaasBilling\Metering\SubscriptionMetering;
use Modules\SaasBilling\Models\PlatformInvoice;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$organizationId, $runs, $barrier] = [(int) $argv[1], (int) $argv[2], $argv[3]];

$organization = Organization::query()->findOrFail($organizationId);

file_put_contents($barrier.'/ready-'.getmypid(), '');

$waitUntil = microtime(true) + 60;

while (! file_exists($barrier.'/go')) {
    if (microtime(true) > $waitUntil) {
        fwrite(STDERR, 'The test never said go.'.PHP_EOL);
        exit(1);
    }

    usleep(2_000);
}

for ($run = 1; $run <= $runs; $run++) {
    $started = microtime(true);

    try {
        if ($run % 2 === 0) {
            // The hourly job: its cache lock is this process's own (array store), so only the database keeps it to one invoice.
            app()->call([new MeterUnits, 'handle']);
            $invoice = null;
        } else {
            $invoice = app(OrganizationContext::class)->run($organization, fn (): ?PlatformInvoice => app(SubscriptionMetering::class)->billDue());
        }
    } catch (Throwable $failure) {
        echo json_encode(['pid' => getmypid(), 'run' => $run, 'error' => $failure::class.': '.$failure->getMessage()], JSON_THROW_ON_ERROR).PHP_EOL;

        continue;
    }

    echo json_encode(['pid' => getmypid(), 'run' => $run, 'invoice' => $invoice?->number, 'started' => $started, 'ended' => microtime(true)], JSON_THROW_ON_ERROR).PHP_EOL;
    usleep(random_int(0, 1_000));
}
