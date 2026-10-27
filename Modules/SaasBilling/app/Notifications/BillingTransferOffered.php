<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Notifications;

use App\Theme\ThemeContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * To each owner of the organization a bill is offered to
 * (BILLING_PAYMENTS.md § 7): who offers it, roughly what it costs today,
 * and the link to the accept screen (/billing-transfers/{token}, which
 * opens it in their workspace after sign-in). By email and in the app
 * (the stored copy links to the workspace page, never the token); never
 * SMS or WhatsApp. Queued on `notifications`, after the commit.
 */
final class BillingTransferOffered extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        #[SensitiveParameter] private readonly string $token,
        public readonly int $transferId,
        public readonly string $fromOrganization,
        public readonly string $toOrganization,
        public readonly string $toSlug,
        public readonly string $offeredBy,
        public readonly string $monthlyAmount,
        public readonly CarbonImmutable $expiresAt,
    ) {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function url(): string
    {
        return route('billing-transfers.open', ['token' => $this->token]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brand = app(ThemeContext::class)->current()->name();

        return (new MailMessage)
            ->subject("{$this->fromOrganization} asks {$this->toOrganization} to take over its {$brand} bill")
            ->line("{$this->offeredBy} of {$this->fromOrganization} asks {$this->toOrganization} to take over its {$brand} subscription bill: {$this->monthlyAmount} a month today, before VAT.")
            ->line("Nothing changes unless an owner of {$this->toOrganization} accepts. The accept screen shows the exact amount.")
            ->action('Review the offer', $this->url())
            ->line('The offer expires on '.$this->expiresAt->timezone('Africa/Lagos')->format('j F Y').'. If you did not expect it, you can ignore this email.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'billing_transfer.offered',
            'transfer_id' => $this->transferId,
            'from_organization' => $this->fromOrganization,
            'to_organization' => $this->toOrganization,
            'monthly_amount' => $this->monthlyAmount,
            'expires_at' => $this->expiresAt->toIso8601String(),
            'url' => route('workspace.billing-transfers.show', ['organization' => $this->toSlug, 'transfer' => $this->transferId]),
        ];
    }
}
