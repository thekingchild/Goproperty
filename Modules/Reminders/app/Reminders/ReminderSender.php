<?php

declare(strict_types=1);

namespace Modules\Reminders\Reminders;

use App\Branding\Branding;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use App\Theme\ThemeContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Reminders\Enums\ReminderChannel;
use Modules\Reminders\Enums\ReminderStatus;
use Modules\Reminders\Enums\SkipReason;
use Modules\Reminders\Models\Reminder;
use Modules\Reminders\Notifications\ReminderNotice;

/**
 * Sends the current organization's pending reminders whose send time has
 * come (P05-01; docs/plans/P05-subtasks.md defaults 6–8): each row locked,
 * checked again (ReminderMessages: still owed, lease still running,
 * recipient still reachable) and then either queued as a notification and
 * marked `sent`, or marked `skipped` with the reason. A reminder whose
 * Lagos day has ended is skipped as `missed`: no catch-up.
 *
 * Once (AGENT.md § 3 rule 6): the row lock and its status make a rerun,
 * or two runs at once, send nothing twice; the notification carries the
 * reminder's `notification_id`, so its own retries write no second in-app
 * row and send no second email (ReminderNotice::shouldSend, `delivered_at`).
 */
final readonly class ReminderSender
{
    private const int PAGE = 100;

    public function __construct(
        private OrganizationContext $context,
        private ReminderMessages $messages,
        private ThemeContext $theme,
    ) {}

    /**
     * @param  Closure(): bool  $stop
     * @return bool false when stopped before the end
     */
    public function send(CarbonImmutable $now, CarbonImmutable $today, Closure $stop): bool
    {
        $organization = $this->context->get();
        $afterId = 0;

        do {
            $ids = Reminder::query()
                ->where('status', ReminderStatus::Pending)
                ->where('send_at', '<=', $now)
                ->where('id', '>', $afterId)
                ->orderBy('id')
                ->limit(self::PAGE)
                ->pluck('id')
                ->all();

            foreach ($ids as $id) {
                if ($stop()) {
                    return false;
                }

                $afterId = (int) $id;
                $this->sendOne($organization, (int) $id, $today, $now);
            }
        } while (count($ids) === self::PAGE);

        return true;
    }

    private function sendOne(Organization $organization, int $id, CarbonImmutable $today, CarbonImmutable $now): void
    {
        DB::transaction(function () use ($organization, $id, $today, $now): void {
            $reminder = Reminder::query()->whereKey($id)->lockForUpdate()->first();

            if (! $reminder instanceof Reminder || $reminder->status !== ReminderStatus::Pending) {
                return;
            }

            $message = $reminder->send_on->lessThan($today)
                ? SkipReason::Missed
                : $this->messages->compose($reminder, $organization);

            if ($message instanceof SkipReason) {
                $reminder->status = ReminderStatus::Skipped;
                $reminder->skip_reason = $message;
                $reminder->save();

                return;
            }

            $reminder->status = ReminderStatus::Sent;
            $reminder->sent_at = $now;
            $reminder->save();

            $this->deliver($organization, $reminder, $message);
        }, 3);
    }

    private function deliver(Organization $organization, Reminder $reminder, ReminderMessage $message): void
    {
        $branding = Branding::fromOrganization($organization);
        $user = $reminder->recipient_user_id !== null ? User::query()->find($reminder->recipient_user_id) : null;

        $notification = new ReminderNotice(
            reminderId: $reminder->id,
            type: $reminder->type->value,
            organizationId: $organization->id,
            subject: $message->subject,
            lines: $message->lines,
            url: $message->url,
            actionLabel: $message->actionLabel,
            agency: $branding->displayName(),
            greetingName: $message->greetingName,
            senderName: $branding->senderName($this->theme->current()->name()),
            replyTo: $branding->email,
            channel: $reminder->channel->via(),
        );
        $notification->id = $reminder->notification_id;

        if ($reminder->channel === ReminderChannel::InApp) {
            $user?->notify($notification);

            return;
        }

        Notification::route('mail', (string) $reminder->recipient_email)->notify($notification);
    }
}
