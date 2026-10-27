<?php

declare(strict_types=1);

/*
 * One of the processes tests/Drill/DocumentNumberingDrillTest.php runs side
 * by side, each with its own database connection: it takes document
 * numbers the way an issuing module does, in transactions, and prints what
 * it took as one JSON line per transaction.
 *
 *   php document-numbers.php <organization id> <transactions> <n> <barrier folder>
 *
 * Every transaction takes an invoice number, holds the lock for up to 2 ms
 * (the document being saved), then a receipt number. The first and then
 * every n-th one after it fail and roll back: the first so that the race to
 * create the year's counters also has losers that roll back. The process writes `ready-<pid>` in the barrier
 * folder once booted and starts when the test writes `go` there, so all of
 * them start together.
 */

use App\Documents\DocumentNumberer;
use App\Documents\DocumentType;
use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$organizationId, $transactions, $rollbackEvery, $barrier] = [(int) $argv[1], (int) $argv[2], (int) $argv[3], $argv[4]];

$organization = Organization::query()->findOrFail($organizationId);
$numberer = app(DocumentNumberer::class);

file_put_contents($barrier.'/ready-'.getmypid(), '');

$waitUntil = microtime(true) + 60;

while (! file_exists($barrier.'/go')) {
    if (microtime(true) > $waitUntil) {
        fwrite(STDERR, 'The test never said go.'.PHP_EOL);
        exit(1);
    }

    usleep(2_000);
}

app(OrganizationContext::class)->run($organization, function () use ($numberer, $transactions, $rollbackEvery): void {
    for ($i = 1; $i <= $transactions; $i++) {
        $rollBack = $i % $rollbackEvery === 1;
        $attempts = 0;
        $taken = [];
        $started = microtime(true);

        try {
            // As issuing modules do: a deadlock re-runs the whole transaction.
            DB::transaction(function () use ($numberer, $rollBack, &$attempts, &$taken): void {
                $attempts++;
                $taken = ['invoice' => $numberer->next(DocumentType::Invoice)];
                usleep(random_int(0, 2_000));
                $taken['receipt'] = $numberer->next(DocumentType::Receipt);

                if ($rollBack) {
                    throw new DomainException('The document failed to save.');
                }
            }, 3);
        } catch (DomainException) {
            // Rolled back on purpose.
        }

        echo json_encode([
            'pid' => getmypid(),
            'committed' => ! $rollBack,
            'attempts' => $attempts,
            'started' => $started,
            'ended' => microtime(true),
        ] + $taken, JSON_THROW_ON_ERROR).PHP_EOL;
    }
});
