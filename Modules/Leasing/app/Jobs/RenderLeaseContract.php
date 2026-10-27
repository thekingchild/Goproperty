<?php

declare(strict_types=1);

namespace Modules\Leasing\Jobs;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Leasing\Documents\LeaseContracts;
use Modules\Leasing\Models\LeaseContract;

/**
 * Renders an issued lease contract's PDF once and keeps it as evidence
 * (P03-03b; Documents\LeaseContracts::pdf(), BRANDING_DOCUMENTS.md § 5), on
 * the `pdf` queue after the commit that issued it (AGENT.md § 4: PDFs are
 * queued). Safe to re-run: a contract that already has its PDF is left as
 * it is (DocumentRenderer::stored() returns the kept file).
 */
final class RenderLeaseContract implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public int $timeout = 50;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $contractId,
    ) {
        $this->onQueue('pdf');
        $this->afterCommit();
    }

    public function handle(OrganizationContext $context, LeaseContracts $contracts): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        $context->run($organization, function () use ($contracts): void {
            $contract = LeaseContract::query()->find($this->contractId);

            if ($contract instanceof LeaseContract) {
                $contracts->pdf($contract);
            }
        });
    }
}
