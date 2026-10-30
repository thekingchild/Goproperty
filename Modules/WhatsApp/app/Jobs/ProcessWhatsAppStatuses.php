<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Jobs;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\WhatsApp\Webhooks\StatusUpdates;

/**
 * Applies the statuses of one signed webhook call (AGENT.md § 4: webhook
 * processing is queued, so Meta gets its 200 at once), on `notifications`,
 * inside the organization's context. Encrypted: Meta's errors may name the
 * number. Safe to run again (StatusUpdates never moves backwards).
 */
final class ProcessWhatsAppStatuses implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public int $timeout = 30;

    /** @param  list<array{id: string, status: string, timestamp: int|null, error: string|null}>  $statuses */
    public function __construct(
        public readonly int $organizationId,
        public readonly array $statuses,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(OrganizationContext $context, StatusUpdates $updates): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        $context->run($organization, function () use ($updates): void {
            foreach ($this->statuses as $status) {
                $updates->apply($status);
            }
        });
    }
}
