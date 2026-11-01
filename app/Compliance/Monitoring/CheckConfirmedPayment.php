<?php

declare(strict_types=1);

namespace App\Compliance\Monitoring;

use App\Models\Organization;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Payments\Contracts\PaymentSource;
use Modules\Payments\Events\PaymentConfirmed;

/**
 * Queues the Transaction Tier checks of a confirmed payment once its
 * confirmation is committed (P06-09, PaymentChecks). Imported payment
 * history is never checked (default 86). Registered in AppServiceProvider.
 */
final class CheckConfirmedPayment implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    public string $queue = 'default';

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(private readonly PaymentChecks $checks) {}

    public function shouldQueue(PaymentConfirmed $event): bool
    {
        return $event->source !== PaymentSource::Import->value;
    }

    public function handle(PaymentConfirmed $event): void
    {
        $organization = Organization::query()->find($event->organizationId);

        if ($organization instanceof Organization) {
            $this->checks->check($organization, $event->paymentId);
        }
    }
}
