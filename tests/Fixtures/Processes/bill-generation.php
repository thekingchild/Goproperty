<?php

declare(strict_types=1);

/*
 * One of the processes tests/Drill/BillGenerationDrillTest.php runs side by
 * side, each with its own database connection: it generates the
 * organization's rent bills (Modules\Billing\Bills\RentBillGenerator,
 * straight, without the job's cache lock), so several runs bill the same
 * leases at the same moment, and prints one JSON line: the bills it issued
 * and anything it reported.
 *
 *   php bill-generation.php <organization id> <barrier folder>
 *
 * The process writes `ready-<pid>` in the barrier folder once booted and
 * starts when the test writes `go` there, so all of them start together.
 */

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Modules\Billing\Bills\RentBillGenerator;
use Modules\Billing\Events\BillIssued;
use Modules\Billing\Jobs\RenderInvoice;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$organizationId, $barrier] = [(int) $argv[1], $argv[2]];

$organization = Organization::query()->findOrFail($organizationId);
// Each bill queues its invoice PDF (P03-05c); rendering them is not this drill's business.
Queue::fake([RenderInvoice::class]);
$issued = [];
$reported = [];
Event::listen(BillIssued::class, function (BillIssued $event) use (&$issued): void {
    $issued[] = $event->billId;
});
$handler = app(ExceptionHandler::class);
assert(method_exists($handler, 'reportable'));
$handler->reportable(function (Throwable $failure) use (&$reported): bool {
    $reported[] = $failure::class.': '.$failure->getMessage();

    return false;
});

file_put_contents($barrier.'/ready-'.getmypid(), '');

$waitUntil = microtime(true) + 60;

while (! file_exists($barrier.'/go')) {
    if (microtime(true) > $waitUntil) {
        fwrite(STDERR, 'The test never said go.'.PHP_EOL);
        exit(1);
    }

    usleep(2_000);
}

$started = microtime(true);
$finished = app(OrganizationContext::class)->run($organization, fn (): bool => app(RentBillGenerator::class)->generate());

echo json_encode([
    'pid' => getmypid(),
    'finished' => $finished,
    'issued' => $issued,
    'reported' => $reported,
    'started' => $started,
    'ended' => microtime(true),
], JSON_THROW_ON_ERROR).PHP_EOL;
