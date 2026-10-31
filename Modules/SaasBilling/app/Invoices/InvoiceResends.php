<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Invoices;

use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Models\Subscription;
use App\Platform\Staff\StaffPermission;
use App\Tenancy\OrganizationContext;
use App\Tenancy\OrganizationOwners;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Modules\SaasBilling\Contracts\InvoiceEmailRefused;
use Modules\SaasBilling\Contracts\InvoiceEmails;
use Modules\SaasBilling\Enums\InvoiceKind;
use Modules\SaasBilling\Enums\InvoiceStatus;
use Modules\SaasBilling\Metering\SubscriptionMetering;
use Modules\SaasBilling\Models\PlatformInvoice;
use Modules\SaasBilling\Notifications\PlatformInvoiceIssued;

/**
 * Contracts\InvoiceEmails: the issued invoice's email once more (P06-07b),
 * through the same notification metering sends (PlatformInvoiceIssued),
 * to the same people (owners of the organization and of its payer).
 * Subscription invoices only, open or paid; never a void one. 3 an hour
 * per invoice. One audit entry `platform_invoice.resent` with the staff
 * member as causer.
 */
final readonly class InvoiceResends implements InvoiceEmails
{
    public const int PER_HOUR = 3;

    public function __construct(
        private OrganizationContext $context,
        private OrganizationOwners $owners,
    ) {}

    public function resend(PlatformStaff $staff, int $invoiceId): int
    {
        if (! $staff->holdsStaffPermission(StaffPermission::EmailsResend) && ! $staff->holdsStaffPermission(StaffPermission::InvoicesManage)) {
            throw new InvoiceEmailRefused('Sending an invoice again needs the "Resend emails and invoices" or "Platform invoices" permission.');
        }

        $organizationId = DB::table('platform_invoices')->where('id', $invoiceId)->value('organization_id');
        $organization = is_numeric($organizationId) ? Organization::query()->find((int) $organizationId) : null;

        if (! $organization instanceof Organization) {
            throw new InvoiceEmailRefused('That invoice was not found.');
        }

        return $this->context->run($organization, function () use ($staff, $invoiceId, $organization): int {
            $invoice = PlatformInvoice::query()->findOrFail($invoiceId);

            if ($invoice->kind !== InvoiceKind::Subscription || $invoice->status === InvoiceStatus::Void) {
                throw new InvoiceEmailRefused("Invoice {$invoice->number} has no invoice email to send again.");
            }

            $key = 'platform-invoice-resend:'.$invoice->id;

            if (RateLimiter::tooManyAttempts($key, self::PER_HOUR)) {
                throw new InvoiceEmailRefused("Invoice {$invoice->number} was sent again ".self::PER_HOUR.' times in the last hour. Try later.');
            }

            $recipients = $this->owners->owners($organization);
            $payerId = Subscription::query()->first()?->payerId();
            $payer = $payerId !== null && $payerId !== $organization->id ? Organization::query()->find($payerId) : null;

            if ($payer instanceof Organization) {
                $recipients = $recipients->merge($this->owners->owners($payer))->unique('id');
            }

            if ($recipients->isEmpty()) {
                throw new InvoiceEmailRefused("{$organization->name} has no owner to send invoice {$invoice->number} to.");
            }

            RateLimiter::hit($key, 3600);

            foreach ($recipients as $recipient) {
                $recipient->notify(new PlatformInvoiceIssued($organization->id, $invoice->id));
            }

            activity(SubscriptionMetering::LOG)
                ->event('platform_invoice.resent')
                ->performedOn($invoice)
                ->causedBy($staff)
                ->withProperties(['organization_id' => $organization->id, 'invoice' => $invoice->number, 'recipients' => $recipients->count()])
                ->log("Invoice {$invoice->number} sent again to the owners");

            return $recipients->count();
        });
    }
}
