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
use Modules\SaasBilling\Contracts\InvoiceLine;
use Modules\SaasBilling\Contracts\SubscriptionInvoice;
use Modules\SaasBilling\Invoices\InvoiceTemplates;
use Modules\SaasBilling\Invoices\SubscriptionInvoiceList;
use Modules\SaasBilling\Models\PlatformInvoice;

/**
 * A subscription invoice was issued (P06-02; BILLING_PAYMENTS.md § 3 step
 * 4): to the owners of the organization and of its payer, in the app and
 * by email (when the account has an address), in GoProperty's branding
 * (template `billing.invoice_issued`), linked to Settings → Subscription.
 * Sent by Metering\SubscriptionMetering, queued on `notifications` after
 * the invoice's commit; never for a platform-covered invoice.
 */
final class PlatformInvoiceIssued extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $invoiceId,
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
            'event' => InvoiceTemplates::INVOICE_ISSUED,
            'organization_id' => $this->organizationId,
            'organization' => $this->organization()?->name,
            'invoice_id' => $this->invoiceId,
            'message' => $this->email($notifiable)->subject,
            'url' => $this->url(),
        ];
    }

    private function email(object $notifiable): RenderedEmail
    {
        $invoice = $this->invoice();
        $lines = array_map(static fn (InvoiceLine $line): string => $line->label.': '.$line->amount->format(), $invoice->lines);
        $lines[] = $invoice->subtotalLabel.': '.$invoice->subtotal->format();
        $lines[] = $invoice->vatLabel.': '.$invoice->vat->format();

        return app(EmailTemplates::class)->render(InvoiceTemplates::INVOICE_ISSUED, [
            'recipient.name' => $notifiable instanceof User ? $notifiable->name : null,
            'organization.name' => $this->organization()?->name,
            'invoice.number' => $invoice->number,
            'plan.name' => $invoice->planName,
            'invoice.period' => $invoice->period,
            'invoice.units' => number_format($invoice->units),
            'invoice.lines' => implode("\n", $lines),
            'invoice.total' => $invoice->total->format(),
            'invoice.due_date' => $invoice->dueDate?->format('j F Y'),
        ]);
    }

    /** The invoice as issued, read in its organization. */
    private function invoice(): SubscriptionInvoice
    {
        $organization = $this->organization() ?? Organization::query()->findOrFail($this->organizationId);

        return app(OrganizationContext::class)->run($organization, fn (): SubscriptionInvoice => SubscriptionInvoiceList::present(PlatformInvoice::query()->findOrFail($this->invoiceId)));
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
