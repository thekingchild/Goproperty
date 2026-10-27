<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Theme\ThemeContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * "Invite a member" (ONBOARDING_COMPLIANCE.md § 2): the single-use link to
 * join an organization with its roles, valid 14 days. Sent by email only
 * (never SMS), queued on `notifications` after the commit. The same email
 * whether or not the address already has an account: the acceptance page
 * asks the person to sign in or to set a password.
 */
final class MemberInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  list<string>  $roles  role labels */
    public function __construct(
        #[SensitiveParameter] private readonly string $token,
        public readonly string $organizationName,
        public readonly string $invitedBy,
        public readonly array $roles,
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
        return route('invitations.show', ['token' => $this->token]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brand = app(ThemeContext::class)->current()->name();
        $roles = (count($this->roles) === 1 ? 'Your role: ' : 'Your roles: ').implode(', ', $this->roles).'.';

        return (new MailMessage)
            ->subject("{$this->invitedBy} invited you to {$this->organizationName} on {$brand}")
            ->line("{$this->invitedBy} invited you to join {$this->organizationName} on {$brand}.")
            ->line($roles)
            ->action('Accept the invitation', $this->url())
            ->line("The link works once and expires in {$this->validDays} days. If you did not expect this invitation, you can ignore this email.");
    }
}
