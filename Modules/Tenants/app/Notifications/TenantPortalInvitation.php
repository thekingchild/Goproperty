<?php

declare(strict_types=1);

namespace Modules\Tenants\Notifications;

use App\Theme\ThemeContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * "You're invited to the tenant portal" (ONBOARDING_COMPLIANCE.md § 2
 * "Tenants"): the single-use link to set a password, valid 14 days. Email
 * only (never SMS), queued on `notifications` after the commit, encrypted
 * on the queue (it carries the link). The same email whether or not the
 * address already has an account: the page asks the person to sign in or
 * to set a password.
 */
final class TenantPortalInvitation extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        #[SensitiveParameter] private readonly string $token,
        public readonly string $organizationName,
        public readonly string $invitedBy,
        public readonly string $tenantName,
        public readonly int $validDays,
    ) {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function url(): string
    {
        return route('portal.invitations.show', ['token' => $this->token]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brand = app(ThemeContext::class)->current()->name();

        return (new MailMessage)
            ->subject("{$this->organizationName} invited you to your tenant portal on {$brand}")
            ->greeting("Hello {$this->tenantName},")
            ->line("{$this->invitedBy} of {$this->organizationName} invited you to the tenant portal on {$brand}: your rent, bills and receipts in one place.")
            ->action('Set up your account', $this->url())
            ->line("The link works once and expires in {$this->validDays} days. If you were not expecting this, you can ignore this email.");
    }
}
