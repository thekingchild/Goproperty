<?php

declare(strict_types=1);

namespace Modules\Billing\Listeners;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Modules\Billing\Events\BillIssued;
use Modules\Billing\Events\BillVoided;
use Modules\Billing\Jobs\RenderInvoice;

/**
 * Queues a bill's invoice PDF on `pdf` (Jobs\RenderInvoice) once the
 * transaction that issued the bill has committed, and the VOID copy once a
 * void has (P03-05c; BRANDING_DOCUMENTS.md § 5: issued PDFs are rendered
 * once and kept). A rolled-back issue queues nothing; a PDF problem never
 * undoes a bill (the download renders the PDF if the queue has not).
 */
final readonly class QueueInvoicePdf implements ShouldHandleEventsAfterCommit
{
    public function handle(BillIssued|BillVoided $event): void
    {
        RenderInvoice::dispatch($event->organizationId, $event->billId);
    }
}
