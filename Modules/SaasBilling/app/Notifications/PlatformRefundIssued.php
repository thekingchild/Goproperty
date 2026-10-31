<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Notifications;

use App\Mail\Templates\BrandedMail;
use App\Mail\Templates\EmailBrand;
use App\Mail\Templates\EmailTemplates;
use App\Mail\Templates\RenderedEmail;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use App\Theme\ThemeContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;
use Modules\SaasBilling\Invoices\InvoiceTemplates;
use Modules\SaasBilling\Models\PlatformInvoice;
use Modules\SaasBilling\Models\PlatformRefund;

/**
 * GoProperty refunded a payment it received (P06-07b; BILLING_PAYMENTS.md
 * § 8; docs/plans/P06-subtasks.md default 58): to the organization's
 * owners, in the app and by email (template `billing.refund_issued`,
 * GoProperty's branding), saying how the money comes back and whether the
 * invoice is open again. Sent by Refunds\PlatformRefunds, queued on
 * `notifications` after the refund's commit, once per refund.
 */
final class PlatformRefundIssued extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $refundId,
    ) {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User && $notifiable->email !== null ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return app(BrandedMail::class)->message($this->email($notifiable), EmailBrand::platform(app(ThemeContext::class)->current()), $this->url());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => InvoiceTemplates::REFUND_ISSUED,
            'organization_id' => $this->organizationId,
            'organization' => $this->organization()?->name,
            'refund_id' => $this->refundId,
            'message' => $this->email($notifiable)->subject,
            'url' => $this->url(),
        ];
    }

    private function email(object $notifiable): RenderedEmail
    {
        $organization = $this->organization() ?? Organization::query()->findOrFail($this->organizationId);

        [$refund, $invoice] = app(OrganizationContext::class)->run($organization, function (): array {
            $refund = PlatformRefund::query()->findOrFail($this->refundId);

            return [$refund, PlatformInvoice::query()->findOrFail($refund->platform_invoice_id)];
        });

        return app(EmailTemplates::class)->render(InvoiceTemplates::REFUND_ISSUED, [
            'recipient.name' => $notifiable instanceof User ? $notifiable->name : null,
            'organization.name' => $organization->name,
            'invoice.number' => $invoice->number,
            'refund.amount' => $refund->amount_kobo->format(),
            'refund.date' => ($refund->executed_at ?? $refund->created_at)?->timezone('Africa/Lagos')->format('j F Y'),
            'refund.how' => $refund->method === 'bank_transfer'
                ? (string) __('GoProperty sent it back by bank transfer (reference :reference).', ['reference' => (string) $refund->bank_reference])
                : (string) __('Paystack returns it to the card or account you paid with, usually within 5 to 10 working days.'),
            'invoice.after' => $refund->invoice_reopened
                ? (string) __('Invoice :number is open again, due :date. An owner can pay it from Settings → Subscription.', ['number' => $invoice->number, 'date' => (string) $invoice->due_date?->format('j F Y')])
                : '',
        ]);
    }

    private function url(): ?string
    {
        $organization = $this->organization();

        return $organization !== null && Route::has('workspace.settings.subscription')
            ? route('workspace.settings.subscription', ['organization' => $organization->slug])
            : null;
    }

    private function organization(): ?Organization
    {
        return Organization::query()->find($this->organizationId);
    }
}
