<?php

declare(strict_types=1);

/*
 * One of the processes tests/Drill/StatementMatchingDrillTest.php runs
 * side by side, each with its own database connection: it tries to match
 * statement lines through Modules\Collections\Matching\LineMatches, as the
 * matcher job (`auto`) or an owner matching by hand (`hand:<tenant id>`)
 * would at the same moment, and prints one JSON line per try.
 *
 *   php statement-matches.php <organization id> <user id> <line ids, comma separated> <mode> <barrier folder>
 *
 * Permissions are the policies' tests: here every check passes. The
 * process writes `ready-<pid>` in the barrier folder once booted and starts
 * when the test writes `go` there.
 */

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Collections\Matching\LineMatches;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$organizationId, $userId, $lineIds, $mode, $barrier] = [(int) $argv[1], (int) $argv[2], array_map('intval', explode(',', $argv[3])), $argv[4], $argv[5]];

$organization = Organization::query()->findOrFail($organizationId);
$user = User::query()->findOrFail($userId);
$matches = app(LineMatches::class);
Gate::before(fn (): bool => true);
$tenantId = str_starts_with($mode, 'hand:') ? (int) substr($mode, 5) : null;

file_put_contents($barrier.'/ready-'.getmypid(), '');

$waitUntil = microtime(true) + 60;

while (! file_exists($barrier.'/go')) {
    if (microtime(true) > $waitUntil) {
        fwrite(STDERR, 'The test never said go.'.PHP_EOL);
        exit(1);
    }

    usleep(2_000);
}

app(OrganizationContext::class)->run($organization, function () use ($matches, $user, $lineIds, $tenantId): void {
    foreach ($lineIds as $lineId) {
        $started = microtime(true);
        $line = ['pid' => getmypid(), 'line' => $lineId, 'mode' => $tenantId === null ? 'auto' : 'hand'];

        try {
            if ($tenantId === null) {
                $line += ['outcome' => $matches->matchAutomatically($lineId) === null ? 'skipped' : 'matched'];
            } else {
                $recorded = $matches->matchByHand($user, $lineId, $tenantId, null);
                $line += ['outcome' => 'matched', 'payment' => $recorded->paymentId];
            }
        } catch (ValidationException $refused) {
            $line += ['outcome' => 'refused', 'message' => $refused->getMessage()];
        } catch (Throwable $failure) {
            $line += ['outcome' => 'error', 'message' => $failure::class.': '.$failure->getMessage()];
        }

        echo json_encode($line + ['started' => $started, 'ended' => microtime(true)], JSON_THROW_ON_ERROR).PHP_EOL;
    }
});
