<?php

declare(strict_types=1);

/*
 * One of the processes tests/Drill/PaymentConfirmationDrillTest.php runs
 * side by side, each with its own database connection: it confirms
 * payments through Modules\Payments\Payments\PaymentService, as members
 * pressing Confirm at the same moment would, and prints one JSON line per
 * try.
 *
 *   php payment-confirmations.php <organization id> <user id> <payment ids, comma separated> <split> <barrier folder>
 *
 * <split> is `auto` (the bill the payment was for, then the tenant's open
 * bills, the rest to credit) or `<bill id>:<kobo>` (an explicit split: that
 * amount to that bill). Permissions are the policies' tests: here every
 * check passes. The process writes `ready-<pid>` in the barrier folder once
 * booted and starts when the test writes `go` there.
 */

use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Payments\Models\Payment;
use Modules\Payments\Payments\PaymentService;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$organizationId, $userId, $paymentIds, $split, $barrier] = [(int) $argv[1], (int) $argv[2], array_map('intval', explode(',', $argv[3])), $argv[4], $argv[5]];

$organization = Organization::query()->findOrFail($organizationId);
$user = User::query()->findOrFail($userId);
$service = app(PaymentService::class);
Gate::before(fn (): bool => true);
$allocations = null;

if ($split !== 'auto') {
    [$billId, $kobo] = array_map('intval', explode(':', $split));
    $allocations = [$billId => Money::kobo($kobo)];
}

file_put_contents($barrier.'/ready-'.getmypid(), '');

$waitUntil = microtime(true) + 60;

while (! file_exists($barrier.'/go')) {
    if (microtime(true) > $waitUntil) {
        fwrite(STDERR, 'The test never said go.'.PHP_EOL);
        exit(1);
    }

    usleep(2_000);
}

app(OrganizationContext::class)->run($organization, function () use ($service, $user, $paymentIds, $allocations): void {
    foreach ($paymentIds as $paymentId) {
        $started = microtime(true);
        $line = ['pid' => getmypid(), 'payment' => $paymentId];

        try {
            $payment = $service->confirm($user, Payment::query()->findOrFail($paymentId), null, $allocations);
            $line += ['outcome' => 'confirmed', 'status' => $payment->status->value];
        } catch (ValidationException $refused) {
            $line += ['outcome' => 'refused', 'message' => $refused->getMessage()];
        } catch (Throwable $failure) {
            $line += ['outcome' => 'error', 'message' => $failure::class.': '.$failure->getMessage()];
        }

        echo json_encode($line + ['started' => $started, 'ended' => microtime(true)], JSON_THROW_ON_ERROR).PHP_EOL;
    }
});
