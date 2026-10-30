<?php

declare(strict_types=1);

/*
 * One of the processes tests/Drill/SmsCreditDrillTest.php runs side by
 * side, each with its own database connection (P05-03): it runs
 * App\Sms\SendSms for its own queued deliveries, as a queue worker would,
 * all competing for the same organization's SMS credits, and prints one
 * JSON line per delivery with what became of it. The log SMS driver sends
 * nothing.
 *
 *   php sms-sends.php <organization id> <comma-separated delivery ids> <barrier folder>
 *
 * The process writes `ready-<pid>` in the barrier folder once booted and
 * starts when the test writes `go` there.
 */

use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Sms\SendSms;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$organizationId, $deliveryIds, $barrier] = [(int) $argv[1], array_map('intval', explode(',', $argv[2])), $argv[3]];

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

foreach ($deliveryIds as $deliveryId) {
    $started = microtime(true);

    try {
        app()->call([new SendSms($organizationId, $deliveryId), 'handle']);
        $status = app(OrganizationContext::class)->run($organization, fn (): string => NotificationDelivery::query()->findOrFail($deliveryId)->status->value);
    } catch (Throwable $failure) {
        echo json_encode(['pid' => getmypid(), 'delivery' => $deliveryId, 'error' => $failure::class.': '.$failure->getMessage()], JSON_THROW_ON_ERROR).PHP_EOL;

        continue;
    }

    echo json_encode(['pid' => getmypid(), 'delivery' => $deliveryId, 'status' => $status, 'started' => $started, 'ended' => microtime(true)], JSON_THROW_ON_ERROR).PHP_EOL;
    usleep(random_int(0, 1_000));
}
