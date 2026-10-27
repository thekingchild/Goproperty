<?php

declare(strict_types=1);

namespace Modules\Billing\Jobs;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Billing\Documents\Invoices;
use Modules\Billing\Models\Bill;

/**
 * Renders a bill's invoice PDF once and keeps it as evidence (P03-05c;
 * Documents\Invoices::pdf(), BRANDING_DOCUMENTS.md § 5), on the `pdf`
 * queue after the commit that issued (or voided) the bill (AGENT.md § 4:
 * PDFs are queued). Safe to re-run: an invoice that already has its PDF
 * for the bill's current state is left as it is (DocumentRenderer::stored()
 * returns the kept file).
 */
final class RenderInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public int $timeout = 50;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $billId,
    ) {
        $this->onQueue('pdf');
        $this->afterCommit();
    }

    public function handle(OrganizationContext $context, Invoices $invoices): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        $context->run($organization, function () use ($invoices): void {
            $bill = Bill::query()->find($this->billId);

            if ($bill instanceof Bill) {
                $invoices->pdf($bill);
            }
        });
    }
}
