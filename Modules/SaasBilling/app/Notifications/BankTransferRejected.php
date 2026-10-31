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
use Modules\SaasBilling\Models\PlatformPayment;

/**
 * GoProperty's staff could not accept the proof of a bank transfer sent for
 * a subscription invoice (P06-04; BILLING_PAYMENTS.md § 5 step 3 "rejects
 * with reason (customer notified, may re-upload)"): to the organization's
 * owners, in the app and by email (template `billing.transfer_rejected`,
 * GoProperty's branding) with the reason, linked to Settings →
 * Subscription. Sent by Payments\TransferReviews, queued on `notifications`
 * after the commit, once per proof.
 */
final class BankTransferRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $paymentId,
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
            'event' => InvoiceTemplates::TRANSFER_REJECTED,
            'organization_id' => $this->organizationId,
            'organization' => $this->organization()?->name,
            'payment_id' => $this->paymentId,
            'message' => $this->email($notifiable)->subject,
            'url' => $this->url(),
        ];
    }

    private function email(object $notifiable): RenderedEmail
    {
        $organization = $this->organization() ?? Organization::query()->findOrFail($this->organizationId);

        [$payment, $invoice] = app(OrganizationContext::class)->run($organization, function (): array {
            $payment = PlatformPayment::query()->findOrFail($this->paymentId);

            return [$payment, PlatformInvoice::query()->findOrFail($payment->platform_invoice_id)];
        });

        return app(EmailTemplates::class)->render(InvoiceTemplates::TRANSFER_REJECTED, [
            'recipient.name' => $notifiable instanceof User ? $notifiable->name : null,
            'organization.name' => $organization->name,
            'invoice.number' => $invoice->number,
            'invoice.total' => $invoice->total_kobo->format(),
            'payment.amount' => ($payment->stated_amount_kobo ?? $payment->amount_kobo)->format(),
            'payment.date' => $payment->transferred_on?->format('j F Y'),
            'rejection.reason' => $payment->rejection_reason,
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
