<?php

declare(strict_types=1);

namespace Modules\Payments\Listeners;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Modules\Payments\Events\PaymentConfirmed;
use Modules\Payments\Receipts\Receipts;

/**
 * Issues the receipt of a confirmed payment (P03-10b; ARCHITECTURE.md § 6.4
 * "On confirmation: … a numbered receipt is generated"). Runs when
 * PaymentConfirmed is sent, inside the confirming transaction: the receipt
 * and its number commit with the confirmation or not at all (a rolled-back
 * confirmation gives the number back, AGENT.md § 4). Handled twice, it
 * issues nothing more (Receipts::issue() is idempotent per payment). The
 * PDF is rendered after the commit on `pdf`.
 */
final readonly class IssueReceipt
{
    public function __construct(
        private OrganizationContext $context,
        private Receipts $receipts,
    ) {}

    public function handle(PaymentConfirmed $event): void
    {
        $organization = Organization::query()->findOrFail($event->organizationId);

        $this->context->run($organization, fn () => $this->receipts->issue($event->paymentId));
    }
}
