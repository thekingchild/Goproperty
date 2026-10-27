<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Notifications;

use App\Theme\ThemeContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To each owner of the organization a bill was offered to, when the
 * offering organization withdraws the offer before an answer
 * (BILLING_PAYMENTS.md § 7): its link stops working. By email and in the
 * app; never SMS or WhatsApp. Queued on `notifications`, after the commit.
 */
final class BillingTransferWithdrawn extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $transferId,
        public readonly string $fromOrganization,
        public readonly string $toOrganization,
        public readonly string $toSlug,
    ) {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brand = app(ThemeContext::class)->current()->name();

        return (new MailMessage)
            ->subject("{$this->fromOrganization} withdrew its {$brand} bill offer")
            ->line("{$this->fromOrganization} withdrew its offer to hand {$this->toOrganization} its {$brand} subscription bill. Nothing changes for {$this->toOrganization}, and the link in the earlier email no longer works.");
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'billing_transfer.cancelled',
            'transfer_id' => $this->transferId,
            'from_organization' => $this->fromOrganization,
            'to_organization' => $this->toOrganization,
            'url' => route('workspace.billing-transfers.index', ['organization' => $this->toSlug]),
        ];
    }
}
