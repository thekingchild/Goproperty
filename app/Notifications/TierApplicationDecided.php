<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\TierApplicationStatus;
use App\Theme\ThemeContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To each org-owner when compliance decides on their Transaction Tier
 * application or asks for more information (ONBOARDING_COMPLIANCE.md § 2,
 * § 5.3). Quotes compliance's note to the owner, never the internal reason
 * kept in the audit log.
 */
final class TierApplicationDecided extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly TierApplicationStatus $outcome,
        public readonly string $organizationName,
        public readonly string $organizationSlug,
        public readonly string $tierLabel,
        public readonly ?string $note = null,
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
        $mail = new MailMessage;

        match ($this->outcome) {
            TierApplicationStatus::Approved => $mail
                ->subject("{$this->organizationName} is now at {$this->tierLabel}")
                ->line("Compliance has approved {$this->organizationName}'s application. Your organization is now at {$this->tierLabel}, with its higher limits."),
            TierApplicationStatus::Rejected => $mail
                ->subject("Your {$this->tierLabel} application was not approved")
                ->line("Compliance has not approved {$this->organizationName}'s application for {$this->tierLabel}. Your organization stays at its current tier and keeps working as before."),
            default => $mail
                ->subject("Compliance needs more information for your {$this->tierLabel} application")
                ->line("Compliance is reviewing {$this->organizationName}'s application for {$this->tierLabel} and needs something more from you before they can decide."),
        };

        if ($this->note !== null && $this->note !== '') {
            $mail->line("Message from {$brand} compliance: {$this->note}");
        }

        return $mail
            ->action($this->outcome === TierApplicationStatus::MoreInfoNeeded ? 'Open your application' : 'See your tier', route('workspace.compliance.tier', ['organization' => $this->organizationSlug]))
            ->line("Contact {$brand} support if you have questions.");
    }
}
