<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Theme\ThemeContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To each org-owner when Transaction Tier documents are within 30 days of
 * their expiry date, or past it (ONBOARDING_COMPLIANCE.md § 5.3–5.4,
 * `document_expiring` / `document_expired`). Sent once per document and
 * stage by App\Platform\Compliance\DocumentExpiryCheck.
 */
final class ComplianceDocumentsExpiring extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  list<array{label: string, expires_at: string, expired: bool}>  $documents */
    public function __construct(
        public readonly string $organizationName,
        public readonly string $organizationSlug,
        public readonly array $documents,
    ) {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brand = app(ThemeContext::class)->current()->name();
        $expired = array_filter($this->documents, static fn (array $document): bool => $document['expired']);

        $mail = (new MailMessage)
            ->subject($expired !== [] ? "{$this->organizationName}: compliance documents have expired" : "{$this->organizationName}: compliance documents expire soon")
            ->line("These Transaction Tier documents for {$this->organizationName} need renewing:");

        foreach ($this->documents as $document) {
            $mail->line(($document['expired'] ? 'Expired on ' : 'Expires on ').$document['expires_at'].': '.$document['label']);
        }

        return $mail
            ->line('Please renew them. Your tier does not change on its own; compliance will contact you if they need the new documents.')
            ->action('Open your Transaction Tier', route('workspace.compliance.tier', ['organization' => $this->organizationSlug]))
            ->line("Contact {$brand} support if you have questions.");
    }
}
