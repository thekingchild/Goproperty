<?php

declare(strict_types=1);

namespace App\Sms;

use App\Models\NotificationDelivery;
use App\Tenancy\OrganizationContext;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * The way to send an SMS (P05-02): writes the `notification_deliveries`
 * row in the current organization, then queues App\Sms\SendSms after the
 * commit. The dedupe key makes a replay queue nothing twice (AGENT.md § 3
 * rule 6). The text must already be one GSM page (SmsText; templates
 * guarantee it). The number must be one the caller may text: a verified
 * one (ARCHITECTURE.md § 6.7); the outbox only checks it looks like E.164.
 */
final readonly class SmsOutbox
{
    public function __construct(
        private OrganizationContext $context,
        private SmsSender $sender,
    ) {}

    public function queue(
        string $type,
        string $to,
        string $text,
        string $dedupeKey,
        ?int $userId = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
    ): NotificationDelivery {
        if (preg_match('/^\+[1-9]\d{7,14}$/', $to) !== 1) {
            throw new InvalidArgumentException('An SMS goes to an E.164 number.');
        }

        if ($text === '' || strlen($text) > SmsText::PAGE || SmsText::outside($text) !== []) {
            throw new InvalidArgumentException('An SMS is one page of GSM text (at most '.SmsText::PAGE.' characters).');
        }

        $existing = NotificationDelivery::query()->where('dedupe_key', $dedupeKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        $delivery = new NotificationDelivery;
        $delivery->forceFill([
            'notification_type' => $type,
            'channel' => 'sms',
            'recipient_user_id' => $userId,
            'recipient_contact' => $to,
            'body' => $text,
            'status' => DeliveryStatus::Queued,
            'provider' => $this->sender->name(),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'dedupe_key' => $dedupeKey,
            'queued_at' => now(),
        ]);

        try {
            $delivery->save();
        } catch (UniqueConstraintViolationException) {
            return NotificationDelivery::query()->where('dedupe_key', $dedupeKey)->firstOrFail();
        }

        SendSms::dispatch($this->context->id(), $delivery->id)->afterCommit();

        return $delivery;
    }
}
