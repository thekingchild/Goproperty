<?php

declare(strict_types=1);

namespace Modules\Tickets\Notices;

use App\Branding\Branding;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use App\Theme\ThemeContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Tickets\Lists\TicketParties;
use Modules\Tickets\Models\Ticket;
use Modules\Tickets\Models\TicketNotice;
use Modules\Tickets\Notifications\TicketUpdate;
use Modules\Tickets\Tickets\TicketSettings;

/**
 * Tells a ticket's parties about something that happened on it, each
 * **once** (P04-03; docs/plans/P04-subtasks.md default 9; AGENT.md § 3
 * rule 6). The only writer of `ticket_notices`.
 *
 * tell() takes the announcement's key (unique per thing announced: a move
 * `status:{event key}`, an assignment `assignment:{event key}`, a thread
 * message `message:{id}`), the recipients (TicketRecipients) and
 * what to say (a TicketNoticeText). For each recipient it first writes
 * the notice row — unique per ticket, key and recipient — and queues the
 * notification (Notifications\TicketUpdate, on `notifications` after the
 * commit) in the same transaction, only when the row is new: a retried
 * listener, a replayed event or two events about the same thing tell
 * nobody twice. The notification's own retries are covered too (its
 * in-app row's id is the notice's `notification_id`; the email is skipped
 * once `mailed_at` is set, markMailed()).
 *
 * Emails carry the agency's branding (BRANDING_DOCUMENTS.md § 1): the
 * sender name ("Adeyemi Realty via GoProperty" unless the agency chose its
 * own), replies to the agency's email when it set one, its name as the
 * signature. Links: the portal's ticket page for a tenant with an account,
 * the workspace's ticket page for staff, none for a tenant told by email
 * only (no account). Runs in the ticket's organization.
 */
final readonly class TicketNotifier
{
    public function __construct(
        private OrganizationContext $context,
        private TicketParties $parties,
        private TicketSettings $settings,
        private ThemeContext $theme,
    ) {}

    /**
     * @param  list<TicketRecipient>  $recipients
     * @return int how many were told now (not before)
     */
    public function tell(Ticket $ticket, string $key, array $recipients, TicketNoticeText $text, ?int $actorUserId = null): int
    {
        $recipients = array_values(array_filter(TicketRecipients::once($recipients), static fn (TicketRecipient $to): bool => $to->channels() !== []));

        if ($recipients === [] || $ticket->number === null) {
            return 0;
        }

        $organization = $this->context->get();
        $facts = $this->facts($ticket, $actorUserId);
        $branding = Branding::fromOrganization($organization);
        $told = 0;

        foreach ($recipients as $to) {
            $notificationId = (string) Str::uuid();

            try {
                DB::transaction(function () use ($ticket, $key, $to, $text, $facts, $branding, $organization, $notificationId): void {
                    $notice = new TicketNotice;
                    $notice->forceFill([
                        'ticket_id' => $ticket->id,
                        'key' => $key,
                        'recipient' => $to->recipient,
                        'user_id' => $to->user?->id,
                        'side' => $to->side,
                        'event' => $text->event(),
                        'channels' => $to->channels(),
                        'notification_id' => $notificationId,
                    ])->save();

                    $this->deliver($organization, $branding, $ticket, $to, $text, $facts, $notificationId);
                });
            } catch (UniqueConstraintViolationException) {
                // Told already: each party hears about each thing once.
                continue;
            }

            $told++;
        }

        return $told;
    }

    /** What the notices say about the ticket now. */
    public function facts(Ticket $ticket, ?int $actorUserId): TicketFacts
    {
        [$row] = $this->parties->rows([$ticket]);
        $actor = $actorUserId !== null ? User::query()->find($actorUserId) : null;

        return new TicketFacts(
            ticketId: $ticket->id,
            number: (string) $ticket->number,
            title: $ticket->title,
            status: $ticket->status->label(),
            priority: $ticket->priority->label(),
            unit: $row->unitLabel,
            building: $row->buildingName,
            tenantName: $row->tenantName,
            agency: Branding::fromOrganization($this->context->get())->displayName(),
            actorName: $actor?->name,
            reopenDays: $this->settings->reopenDays(),
        );
    }

    /** Whether the email of this notice already went (a retried mail job sends nothing). */
    public function mailed(int $organizationId, string $notificationId): bool
    {
        $organization = Organization::query()->find($organizationId);

        return $organization !== null && $this->context->run($organization, static fn (): bool => TicketNotice::query()
            ->where('notification_id', $notificationId)->whereNotNull('mailed_at')->exists());
    }

    /** The email of this notice went (Illuminate\Notifications\Events\NotificationSent). */
    public function markMailed(int $organizationId, string $notificationId): void
    {
        $organization = Organization::query()->find($organizationId);

        if ($organization === null) {
            return;
        }

        $this->context->run($organization, static function () use ($notificationId): void {
            $notice = TicketNotice::query()->where('notification_id', $notificationId)->whereNull('mailed_at')->first();

            if ($notice instanceof TicketNotice) {
                $notice->mailed_at = now()->toImmutable();
                $notice->save();
            }
        });
    }

    private function deliver(Organization $organization, Branding $branding, Ticket $ticket, TicketRecipient $to, TicketNoticeText $text, TicketFacts $facts, string $notificationId): void
    {
        if ($to->user !== null) {
            $channels = $to->mailsTheUser() ? [TicketRecipient::DATABASE, TicketRecipient::MAIL] : [TicketRecipient::DATABASE];
            $to->user->notify($this->notification($channels, $organization, $branding, $ticket, $to, $text, $facts, $notificationId));
        }

        if ($to->mailTo !== null && ! $to->mailsTheUser()) {
            Notification::route('mail', $to->mailTo)->notify($this->notification([TicketRecipient::MAIL], $organization, $branding, $ticket, $to, $text, $facts, $notificationId));
        }
    }

    /**
     * The notification, carrying the notice's id (the in-app row's id).
     *
     * @param  list<string>  $channels
     */
    private function notification(array $channels, Organization $organization, Branding $branding, Ticket $ticket, TicketRecipient $to, TicketNoticeText $text, TicketFacts $facts, string $notificationId): TicketUpdate
    {
        $notification = new TicketUpdate(
            event: $text->event(),
            organizationId: $organization->id,
            ticketId: $ticket->id,
            number: $facts->number,
            side: $to->side,
            subject: $text->subject($facts, $to),
            lines: $text->lines($facts, $to),
            url: $this->url($organization, $ticket, $to),
            actionLabel: $to->isTenant() ? (string) __('Open your repair') : (string) __('Open the ticket'),
            agency: $facts->agency,
            greetingName: $to->name,
            senderName: $branding->senderName($this->theme->current()->name()),
            replyTo: $branding->email,
            channels: $channels,
        );
        $notification->id = $notificationId;

        return $notification;
    }

    private function url(Organization $organization, Ticket $ticket, TicketRecipient $to): ?string
    {
        if (! $to->isTenant()) {
            return route('workspace.tickets.show', ['organization' => $organization->slug, 'ticket' => $ticket->id]);
        }

        return $to->user !== null ? route('portal.tickets.show', ['ticket' => $ticket->id]) : null;
    }
}
