<?php

declare(strict_types=1);

/*
 * One of the processes tests/Drill/TicketNumberingDrillTest.php runs side
 * by side, each with its own database connection: it raises tickets for a
 * unit through Modules\Tickets\Tickets\TicketService::createOnBehalf, each
 * inside a transaction of its own (as a page's request would be), and
 * prints one JSON line per ticket. The first and then every n-th ticket's
 * transaction rolls back after the ticket was raised, so the race for the
 * organization's first ticket number also has losers that roll back.
 *
 *   php ticket-raises.php <organization id> <user id> <unit id> <tickets> <n> <barrier folder>
 *
 * Permissions are not what the drill is about (the feature tests cover
 * TicketPolicy), so every ability is allowed here. The process writes
 * `ready-<pid>` in the barrier folder once booted and starts when the test
 * writes `go` there.
 */

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Tickets\Tickets\TicketService;
use Spatie\Permission\PermissionRegistrar;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$organizationId, $userId, $unitId, $tickets, $rollbackEvery, $barrier] = [(int) $argv[1], (int) $argv[2], (int) $argv[3], (int) $argv[4], (int) $argv[5], $argv[6]];

Gate::before(static fn (): bool => true);
$organization = Organization::query()->findOrFail($organizationId);
$user = User::query()->findOrFail($userId);
$service = app(TicketService::class);
// The permission cache (database cache store) is loaded once now, as on a
// warm site, so the processes do not race to write it inside their tickets'
// transactions: the drill is about the number counter.
app(PermissionRegistrar::class)->getPermissions();

file_put_contents($barrier.'/ready-'.getmypid(), '');

$waitUntil = microtime(true) + 60;

while (! file_exists($barrier.'/go')) {
    if (microtime(true) > $waitUntil) {
        fwrite(STDERR, 'The test never said go.'.PHP_EOL);
        exit(1);
    }

    usleep(2_000);
}

app(OrganizationContext::class)->run($organization, function () use ($service, $user, $unitId, $tickets, $rollbackEvery): void {
    for ($i = 1; $i <= $tickets; $i++) {
        $rollBack = $i % $rollbackEvery === 1;
        $number = null;
        $started = microtime(true);

        try {
            DB::transaction(function () use ($service, $user, $unitId, $i, $rollBack, &$number): void {
                $number = $service->createOnBehalf($user, [
                    'unitId' => $unitId,
                    'category' => 'plumbing',
                    'priority' => 'medium',
                    'title' => 'Drill ticket '.getmypid().'-'.$i,
                    'description' => 'Raised by the numbering drill.',
                ])->number;
                usleep(random_int(0, 2_000));

                if ($rollBack) {
                    throw new DomainException('The page failed after raising the ticket.');
                }
            }, 3);
        } catch (DomainException) {
            // Rolled back on purpose.
        } catch (Throwable $failure) {
            echo json_encode(['pid' => getmypid(), 'error' => $failure::class.': '.$failure->getMessage()], JSON_THROW_ON_ERROR).PHP_EOL;

            continue;
        }

        echo json_encode([
            'pid' => getmypid(),
            'committed' => ! $rollBack,
            'number' => $number,
            'started' => $started,
            'ended' => microtime(true),
        ], JSON_THROW_ON_ERROR).PHP_EOL;
    }
});
