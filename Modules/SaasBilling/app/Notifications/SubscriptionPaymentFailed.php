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
use Modules\SaasBilling\Dunning\DunningTemplates;
use Modules\SaasBilling\Models\PlatformInvoice;
use Modules\SaasBilling\Models\PlatformPayment;

/**
 * A payment of a subscription invoice through GoProperty's Paystack failed
 * (P06-05; BILLING_PAYMENTS.md § 6 "card charge fails"): to the
 * organization's owners, in the app and by email (template
 * `billing.payment_failed`) with a Pay now link to Settings → Subscription.
 * Once per failed payment, after its commit (Payments\PlatformPaymentSettlement).
 * No card is kept, so nothing is charged again by itself (the product
 * owner's decisions of 2026-10-30 and 2026-10-31): the dunning ladder
 * follows the invoice's due date.
 */
final class SubscriptionPaymentFailed extends Notification implements ShouldQueue
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
            'event' => DunningTemplates::PAYMENT_FAILED,
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

        /** @var array{0: PlatformPayment, 1: PlatformInvoice} $read */
        $read = app(OrganizationContext::class)->run($organization, function (): array {
            $payment = PlatformPayment::query()->findOrFail($this->paymentId);

            return [$payment, PlatformInvoice::query()->findOrFail($payment->platform_invoice_id)];
        });
        [$payment, $invoice] = $read;

        return app(EmailTemplates::class)->render(DunningTemplates::PAYMENT_FAILED, [
            'recipient.name' => $notifiable instanceof User ? $notifiable->name : null,
            'organization.name' => $organization->name,
            'invoice.number' => $invoice->number,
            'invoice.total' => $invoice->total_kobo->format(),
            'invoice.due_date' => $invoice->due_date?->format('j F Y'),
            'payment.amount' => $payment->amount_kobo->format(),
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
