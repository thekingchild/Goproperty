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
use Modules\SaasBilling\Invoices\SubscriptionInvoiceList;
use Modules\SaasBilling\Models\PlatformInvoice;
use Modules\SaasBilling\Models\PlatformPayment;

/**
 * GoProperty confirmed a payment of a subscription invoice (P06-03;
 * BILLING_PAYMENTS.md § 4.2 "receipt + notifications"): to the
 * organization's owners, in the app and by email (template
 * `billing.payment_received`, GoProperty's branding), linked to Settings →
 * Subscription. Sent by Payments\PlatformPaymentSettlement, queued on
 * `notifications` after the payment's commit, once per payment.
 */
final class PlatformPaymentReceived extends Notification implements ShouldQueue
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
            'event' => InvoiceTemplates::PAYMENT_RECEIVED,
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

        return app(EmailTemplates::class)->render(InvoiceTemplates::PAYMENT_RECEIVED, [
            'recipient.name' => $notifiable instanceof User ? $notifiable->name : null,
            'organization.name' => $organization->name,
            'invoice.number' => $invoice->number,
            'invoice.period' => SubscriptionInvoiceList::present($invoice)->period,
            'payment.amount' => ($payment->paid_amount_kobo ?? $payment->amount_kobo)->format(),
            'payment.date' => $payment->confirmed_at?->timezone('Africa/Lagos')->format('j F Y'),
            'payment.reference' => $payment->paystack_reference,
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
