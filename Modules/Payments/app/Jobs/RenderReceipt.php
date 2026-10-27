<?php

declare(strict_types=1);

namespace Modules\Payments\Jobs;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Payments\Models\Receipt;
use Modules\Payments\Receipts\Receipts;

/**
 * Renders a receipt's PDF once and keeps it as evidence (P03-10b;
 * Receipts\Receipts::pdf(), BRANDING_DOCUMENTS.md § 5), on the `pdf` queue
 * after the commit that issued it (AGENT.md § 4: PDFs are queued); with
 * $void, a voided receipt's VOID copy (Receipts::voidPdf(), a second file:
 * the original is kept). Safe to re-run: a kept PDF is never rendered again.
 */
final class RenderReceipt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public int $timeout = 50;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $receiptId,
        public readonly bool $void = false,
    ) {
        $this->onQueue('pdf');
        $this->afterCommit();
    }

    public function handle(OrganizationContext $context, Receipts $receipts): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        $context->run($organization, function () use ($receipts): void {
            $receipt = Receipt::query()->find($this->receiptId);

            if (! $receipt instanceof Receipt) {
                return;
            }

            if ($this->void) {
                if ($receipt->isVoided()) {
                    $receipts->voidPdf($receipt);
                }

                return;
            }

            $receipts->pdf($receipt);
        });
    }
}
