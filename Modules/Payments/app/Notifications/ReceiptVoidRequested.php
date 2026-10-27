<?php

declare(strict_types=1);

namespace Modules\Payments\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the organization's other owners and administrators when a member asks
 * for a receipt to be voided (P03-10b, P03 decision 4): a void needs one of
 * them to approve it. In the app and by email; queued on `notifications`
 * after the commit. Names and the receipt number only.
 */
final class ReceiptVoidRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $receiptId,
        public readonly string $number,
        public readonly string $amount,
        public readonly string $requestedBy,
        public readonly string $reason,
        public readonly string $organizationName,
        public readonly string $url,
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
        return (new MailMessage)
            ->subject("{$this->requestedBy} asks to void receipt {$this->number}")
            ->line("{$this->requestedBy} of {$this->organizationName} asks to void receipt {$this->number} ({$this->amount}).")
            ->line("Reason: {$this->reason}")
            ->line('A receipt is voided only once another owner or administrator approves it.')
            ->action('Review the request', $this->url);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'receipt.void_requested',
            'receipt_id' => $this->receiptId,
            'number' => $this->number,
            'amount' => $this->amount,
            'requested_by' => $this->requestedBy,
            'reason' => $this->reason,
            'organization' => $this->organizationName,
            'url' => $this->url,
        ];
    }
}
