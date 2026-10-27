<?php

declare(strict_types=1);

/*
 * One of the processes tests/Drill/LeaseActivationDrillTest.php runs side
 * by side, each with its own database connection: it tries to activate
 * every draft lease it is given, in its own shuffled order, through
 * Modules\Leasing\Leases\LeaseService, and prints one JSON line per try.
 *
 *   php lease-activations.php <organization id> <user id> <barrier folder> <seed> <lease id,lease id,…>
 *
 * Permissions are not what the drill is about (the feature tests cover
 * LeasePolicy), so every ability is allowed here; the unit row lock, the
 * lease row lock and the database's one-active-lease-per-unit index are.
 * The process writes `ready-<pid>` in the barrier folder once booted and
 * starts when the test writes `go` there, so all of them start together.
 */

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Gate;
use Modules\Leasing\Leases\LeaseRefused;
use Modules\Leasing\Leases\LeaseService;
use Modules\Leasing\Models\Lease;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$organizationId, $userId, $barrier, $seed, $leaseIds] = [(int) $argv[1], (int) $argv[2], $argv[3], (int) $argv[4], array_map('intval', explode(',', $argv[5]))];

Gate::before(static fn (): bool => true);
$organization = Organization::query()->findOrFail($organizationId);
$user = User::query()->findOrFail($userId);
$leases = app(LeaseService::class);
mt_srand($seed);
shuffle($leaseIds);

file_put_contents($barrier.'/ready-'.getmypid(), '');

$waitUntil = microtime(true) + 60;

while (! file_exists($barrier.'/go')) {
    if (microtime(true) > $waitUntil) {
        fwrite(STDERR, 'The test never said go.'.PHP_EOL);
        exit(1);
    }

    usleep(2_000);
}

app(OrganizationContext::class)->run($organization, function () use ($leases, $user, $leaseIds): void {
    foreach ($leaseIds as $id) {
        $started = microtime(true);
        $line = ['pid' => getmypid(), 'lease' => $id];

        try {
            // A stale copy, as a page holds it: the service locks and re-reads.
            $lease = $leases->activate($user, Lease::query()->findOrFail($id));
            $line += ['outcome' => 'activated', 'contract' => $lease->contract_number, 'unit' => $lease->unit_id];
        } catch (LeaseRefused $refused) {
            $line += ['outcome' => 'refused', 'message' => $refused->getMessage()];
        } catch (Throwable $failure) {
            $line += ['outcome' => 'error', 'message' => $failure::class.': '.$failure->getMessage()];
        }

        echo json_encode($line + ['started' => $started, 'ended' => microtime(true)], JSON_THROW_ON_ERROR).PHP_EOL;
    }
});
