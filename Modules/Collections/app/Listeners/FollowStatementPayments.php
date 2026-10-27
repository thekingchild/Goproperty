<?php

declare(strict_types=1);

namespace Modules\Collections\Listeners;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Modules\Collections\Matching\LineMatches;
use Modules\Payments\Contracts\PaymentSource;
use Modules\Payments\Events\PaymentConfirmed;
use Modules\Payments\Events\PaymentRejected;

/**
 * A statement line follows the payment recorded from it (P03-07b): when a
 * member confirms that payment (in the confirmation queue, in bulk, or by
 * matching by hand) the line is `matched`; when they reject it, the line
 * is offered for matching by hand again. Handled at once, inside the
 * payment's transaction, so the line and its payment never disagree.
 */
final readonly class FollowStatementPayments
{
    public function __construct(
        private OrganizationContext $context,
        private LineMatches $matches,
    ) {}

    public function handle(PaymentConfirmed|PaymentRejected $event): void
    {
        if ($event->source !== PaymentSource::StatementMatch->value) {
            return;
        }

        $follow = $event instanceof PaymentConfirmed
            ? fn () => $this->matches->paymentConfirmed($event->paymentId, $event->confirmedByUserId)
            : fn () => $this->matches->paymentRejected($event->paymentId, $event->rejectedByUserId);

        if ($this->context->has() && $this->context->id() === $event->organizationId) {
            $follow();

            return;
        }

        $organization = Organization::query()->find($event->organizationId);

        if ($organization !== null) {
            $this->context->run($organization, $follow);
        }
    }
}
