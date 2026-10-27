<?php

declare(strict_types=1);

namespace Modules\Payments\Listeners;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Modules\Payments\Events\PaymentConfirmed;
use Modules\Payments\Events\PaymentRecorded;
use Modules\Payments\Events\PaymentRejected;
use Modules\Payments\Events\ReceiptVoided;
use Modules\Payments\Notices\PaymentNoticeEvent;
use Modules\Payments\Notices\TenantNotices;
use Throwable;

/**
 * Tells the tenant about every payment transition (P03-10b; ARCHITECTURE.md
 * § 6.4): recorded, confirmed (with the receipt), rejected, receipt voided.
 * After the transition's transaction commits, so a refused or rolled-back
 * step tells nobody; once per transition (Notices\TenantNotices keeps a row
 * per payment and event). A failure to notify is reported and never undoes
 * the payment step.
 */
final readonly class TellTenantAboutPayment implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private OrganizationContext $context,
        private TenantNotices $notices,
    ) {}

    public function handle(PaymentRecorded|PaymentConfirmed|PaymentRejected|ReceiptVoided $event): void
    {
        $organization = Organization::query()->find($event->organizationId);

        if ($organization === null) {
            return;
        }

        [$notice, $extra] = match (true) {
            $event instanceof PaymentRecorded => [PaymentNoticeEvent::Recorded, []],
            $event instanceof PaymentConfirmed => [PaymentNoticeEvent::Confirmed, []],
            $event instanceof PaymentRejected => [PaymentNoticeEvent::Rejected, ['reason' => $event->reason]],
            $event instanceof ReceiptVoided => [PaymentNoticeEvent::ReceiptVoided, ['reason' => $event->reason]],
        };

        try {
            $this->context->run($organization, fn () => $this->notices->send($notice, $event->paymentId, $extra));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
