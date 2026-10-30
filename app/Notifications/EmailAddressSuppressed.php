<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * In the app only (P05-04; PLATFORM_CONSOLE.md § 5 "Delivery": "Suppressed
 * users get an in-app notice to fix their email"): email to the account's
 * address bounced or was reported as spam, so nothing more is sent there
 * until it is changed or GoProperty allows it again.
 */
final class EmailAddressSuppressed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $reason)
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'email.suppressed',
            'reason' => $this->reason,
            'message' => $this->reason === 'complaint'
                ? 'An email to your address was marked as spam, so we have stopped emailing you. Check your email address in your profile, or contact support to start again.'
                : 'Emails to your address cannot be delivered, so we have stopped sending them. Check your email address in your profile.',
            'url' => route('profile.edit'),
        ];
    }
}
