<?php

declare(strict_types=1);

namespace Modules\Reminders\Reminders;

use App\Mail\Templates\EmailBrand;
use App\Mail\Templates\EmailTemplates;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\Channels\WhatsAppRouting;
use App\Sms\SmsOutbox;
use App\Tenancy\OrganizationContext;
use App\Theme\ThemeContext;
use App\WhatsApp\SendWhatsAppMessage;
use App\WhatsApp\WhatsAppMessage;
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
 * WhatsApp (P05-06): a WhatsApp reminder whose tenant opted out or changed
 * number, or whose event's template can no longer be sent (paused at
 * Meta, connection failing), is skipped as `fell_back` and the event's
 * next channel (SMS on an SMS rung) is planned and sent at once
 * (ReminderPlanner::fallBack); so is one Meta refuses or later reports
 * failed (Listeners\FallBackFromWhatsApp).
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
        private EmailTemplates $templates,
        private SmsOutbox $sms,
        private WhatsAppRouting $whatsapp,
        private ReminderPlanner $planner,
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
                $next = $this->sendOne($organization, (int) $id, $today, $now);

                if ($next !== null) {
                    $this->sendOne($organization, $next, $today, $now);
                }
            }
        } while (count($ids) === self::PAGE);

        return true;
    }

    /**
     * A planned reminder sent now, if its time has come (a fallback planned
     * after WhatsApp failed, P05-06), in the current organization.
     */
    public function sendPlanned(int $id): void
    {
        $organization = $this->context->get();
        $now = CarbonImmutable::now();
        $next = $this->sendOne($organization, $id, ReminderClock::today(), $now);

        if ($next !== null) {
            $this->sendOne($organization, $next, ReminderClock::today(), $now);
        }
    }

    /** @return int|null the reminder planned instead, to send next (WhatsApp fell back) */
    private function sendOne(Organization $organization, int $id, CarbonImmutable $today, CarbonImmutable $now): ?int
    {
        return DB::transaction(function () use ($organization, $id, $today, $now): ?int {
            $reminder = Reminder::query()->whereKey($id)->lockForUpdate()->first();

            if (! $reminder instanceof Reminder || $reminder->status !== ReminderStatus::Pending || $reminder->send_at === null || $reminder->send_at->greaterThan($now)) {
                return null;
            }

            $message = $reminder->send_on->lessThan($today)
                ? SkipReason::Missed
                : $this->messages->compose($reminder, $organization);

            if ($reminder->channel === ReminderChannel::WhatsApp && $this->fallsBack($reminder, $message)) {
                $next = $this->planner->fallBack($reminder, $now);
                $reminder->status = ReminderStatus::Skipped;
                $reminder->skip_reason = $next !== null ? SkipReason::FellBack : SkipReason::Unreachable;
                $reminder->save();

                return $next?->id;
            }

            if ($message instanceof SkipReason) {
                $reminder->status = ReminderStatus::Skipped;
                $reminder->skip_reason = $message;
                $reminder->save();

                return null;
            }

            $reminder->status = ReminderStatus::Sent;
            $reminder->sent_at = $now;
            $reminder->save();

            $this->deliver($organization, $reminder, $message);

            return null;
        }, 3);
    }

    /**
     * Whether a WhatsApp reminder goes by its next channel instead: the
     * tenant can no longer be reached by WhatsApp (opted out, changed
     * number), or the template of the message as it now stands cannot be
     * sent. A settled bill or an ended lease is skipped as usual.
     */
    private function fallsBack(Reminder $reminder, ReminderMessage|SkipReason $message): bool
    {
        if ($message instanceof SkipReason) {
            return $message === SkipReason::Unreachable;
        }

        return ! $this->whatsapp->usable(ReminderEvents::forTemplate($reminder->type, abs($reminder->offset_days), $message->template));
    }

    private function deliver(Organization $organization, Reminder $reminder, ReminderMessage $message): void
    {
        if ($reminder->channel === ReminderChannel::Sms) {
            // One GSM page from the SMS template (P05-02), sent through Core's
            // outbox: a delivery row first, so a replay sends nothing twice.
            $this->sms->queue(
                type: 'reminder.'.$reminder->type->value,
                to: (string) $reminder->recipient_phone,
                text: $this->templates->renderSms(ReminderTemplates::sms($message->template), $message->values),
                dedupeKey: 'reminder:'.$reminder->id,
                userId: $reminder->recipient_user_id,
                sourceType: Reminder::SMS_SOURCE,
                sourceId: $reminder->id,
            );

            return;
        }

        if ($reminder->channel === ReminderChannel::WhatsApp) {
            // The agency's approved template for this rung (P05-06), filled
            // with the same values as the SMS; the text is the SMS wording.
            // A refusal or a later `failed` falls back (FallBackFromWhatsApp).
            SendWhatsAppMessage::dispatch($organization->id, new WhatsAppMessage(
                to: (string) $reminder->recipient_phone,
                event: ReminderEvents::forTemplate($reminder->type, abs($reminder->offset_days), $message->template),
                text: $this->templates->renderSms(ReminderTemplates::sms($message->template), $message->values),
                variables: $message->values,
                dedupeKey: 'reminder:'.$reminder->id,
                sourceType: Reminder::SMS_SOURCE,
                sourceId: $reminder->id,
                userId: $reminder->recipient_user_id,
            ));

            return;
        }

        $user = $reminder->recipient_user_id !== null ? User::query()->find($reminder->recipient_user_id) : null;

        $notification = new ReminderNotice(
            reminderId: $reminder->id,
            type: $reminder->type->value,
            organizationId: $organization->id,
            email: $this->templates->render($message->template, $message->values),
            brand: EmailBrand::forOrganization($organization, $this->theme->current()),
            url: $message->url,
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
