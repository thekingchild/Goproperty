<?php

declare(strict_types=1);

namespace Modules\Tickets\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Tickets\Notices\TicketNotifier;

/**
 * Something happened on a maintenance ticket (P04-03; ARCHITECTURE.md
 * § 6.6 "Notifications"): a move of its state machine, or an assignment.
 * In the app (`database`, linking to the portal's ticket page for the
 * tenant, the workspace's for staff, the owner portal's for the building's
 * owner, P04-06) and by email in the agency's name
 * (BRANDING_DOCUMENTS.md § 1: the sender name "Adeyemi Realty via
 * GoProperty" or the agency's own, replies to the agency's email). Built
 * from plain strings by Notices\TicketNotifier, which writes the
 * `ticket_notices` row first, so each party is told once. Queued on
 * `notifications` after the commit; each channel is its own job, and a
 * retried one sends nothing twice: the in-app row's id is the notice's
 * (`notifications.id`), and the email is skipped once the notice says it
 * went (`mailed_at`, TicketNotifier::markMailed).
 */
final class TicketUpdate extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @param  list<string>  $lines
     * @param  list<string>  $channels  `database` and/or `mail`
     */
    public function __construct(
        public readonly string $event,
        public readonly int $organizationId,
        public readonly int $ticketId,
        public readonly string $number,
        public readonly string $side,
        public readonly string $subject,
        public readonly array $lines,
        public readonly ?string $url,
        public readonly string $actionLabel,
        public readonly string $agency,
        public readonly string $greetingName,
        public readonly string $senderName,
        public readonly ?string $replyTo,
        public readonly array $channels,
    ) {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<int> seconds before each retry */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    /** A retried job sends nothing twice (see the class comment). */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return match ($channel) {
            'database' => ! DatabaseNotification::query()->whereKey($this->id)->exists(),
            'mail' => ! app(TicketNotifier::class)->mailed($this->organizationId, (string) $this->id),
            default => true,
        };
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->from((string) config('mail.from.address'), $this->senderName)
            ->subject($this->subject)
            ->greeting(__('Hello :name,', ['name' => $this->greetingName]));

        if ($this->replyTo !== null) {
            $mail->replyTo($this->replyTo, $this->agency);
        }

        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        if ($this->url !== null) {
            $mail->action($this->actionLabel, $this->url);
        }

        return $mail->salutation($this->agency);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => $this->event,
            'organization_id' => $this->organizationId,
            'ticket_id' => $this->ticketId,
            'number' => $this->number,
            'side' => $this->side,
            'organization' => $this->agency,
            'message' => $this->subject,
            'url' => $this->url,
        ];
    }
}
