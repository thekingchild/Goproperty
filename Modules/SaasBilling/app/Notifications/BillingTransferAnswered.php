<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Notifications;

use App\Theme\ThemeContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use LogicException;
use Modules\SaasBilling\Enums\BillingTransferStatus;

/**
 * To whoever offered the bill (BILLING_PAYMENTS.md § 7 rule 4: the
 * initiator hears of every state change) when the other organization
 * accepts or declines, or when the offer expires unanswered. By email and
 * in the app; never SMS or WhatsApp. Queued on `notifications`, after the
 * commit.
 */
final class BillingTransferAnswered extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly BillingTransferStatus $outcome,
        public readonly int $transferId,
        public readonly string $fromOrganization,
        public readonly string $fromSlug,
        public readonly string $toOrganization,
        public readonly ?string $monthlyAmount = null,
    ) {
        if (! in_array($outcome, [BillingTransferStatus::Completed, BillingTransferStatus::Declined, BillingTransferStatus::Expired], true)) {
            throw new LogicException("A billing transfer is not answered by \"{$outcome->value}\".");
        }

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
        $mail = new MailMessage;

        match ($this->outcome) {
            BillingTransferStatus::Completed => $mail
                ->subject("{$this->toOrganization} accepted to take over your {$brand} bill")
                ->line("{$this->toOrganization} accepted to take over {$this->fromOrganization}'s {$brand} subscription bill ({$this->monthlyAmount} a month before VAT, as shown to them). {$brand} invoices go to them from here on."),
            BillingTransferStatus::Declined => $mail
                ->subject("{$this->toOrganization} declined to take over your {$brand} bill")
                ->line("{$this->toOrganization} declined to take over {$this->fromOrganization}'s {$brand} subscription bill. Nothing changes: {$this->fromOrganization} keeps paying it."),
            default => $mail
                ->subject("Your offer to {$this->toOrganization} expired")
                ->line("{$this->toOrganization} did not answer within 14 days, so the offer to take over {$this->fromOrganization}'s {$brand} bill expired. Nothing changes. You can offer it again."),
        };

        return $mail->action('See billing transfers', $this->url());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => match ($this->outcome) {
                BillingTransferStatus::Completed => 'billing_transfer.accepted',
                BillingTransferStatus::Declined => 'billing_transfer.declined',
                default => 'billing_transfer.expired',
            },
            'transfer_id' => $this->transferId,
            'from_organization' => $this->fromOrganization,
            'to_organization' => $this->toOrganization,
            'monthly_amount' => $this->monthlyAmount,
            'url' => $this->url(),
        ];
    }

    private function url(): string
    {
        return route('workspace.billing-transfers.index', ['organization' => $this->fromSlug]);
    }
}
