<?php

declare(strict_types=1);

namespace Modules\Payments\Listeners;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Modules\Payments\Contracts\PaymentSource;
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
 * Statement matches (P03-07b) are told only when confirmed.
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
        // A statement match is the agency's guess from its bank statement, not
        // something the tenant sent: it may be someone else's money. The tenant
        // hears only once the agency confirms it (P03-07b).
        if (($event instanceof PaymentRecorded || $event instanceof PaymentRejected) && $event->source === PaymentSource::StatementMatch->value) {
            return;
        }

        // Imported history is never told to the tenant (P03-13b; DATA_IMPORT.md
        // § 3: an import contacts nobody), whether or not a receipt was asked.
        if (! $event instanceof ReceiptVoided && $event->source === PaymentSource::Import->value) {
            return;
        }

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
