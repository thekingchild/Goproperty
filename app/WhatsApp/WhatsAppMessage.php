<?php

declare(strict_types=1);

namespace App\WhatsApp;

use SensitiveParameter;

/**
 * One WhatsApp message for one number. `event` names what it is about
 * (e.g. `tenant_invitation`, `reminder.rent_due.t7`; WhatsAppEvents lists
 * them): the cloud driver (P05-06) sends the agency's approved Meta
 * template mapped to that event, each template variable filled from
 * `variables` (placeholder => value). `text` is the plain message the
 * template stands for. Links in it may carry a one-time token, so the
 * whole message is treated as a secret (never logged, encrypted on the
 * queue).
 *
 * `dedupeKey` makes the delivery row once (`notification_deliveries`; the
 * queued job sets one when the caller gave none, so its retries share it);
 * `sourceType`/`sourceId` say what asked for it (e.g. `reminder` and its
 * id), handed back in WhatsAppSent and WhatsAppFailed.
 */
final readonly class WhatsAppMessage
{
    /** @param  array<string, string>  $variables  placeholder => value, e.g. 'tenant.first_name' => 'Ada' */
    public function __construct(
        public string $to,
        public string $event,
        #[SensitiveParameter] public string $text,
        #[SensitiveParameter] public array $variables = [],
        public ?string $dedupeKey = null,
        public ?string $sourceType = null,
        public ?int $sourceId = null,
        public ?int $userId = null,
    ) {}

    /** The same message with a dedupe key. */
    public function withDedupeKey(string $dedupeKey): self
    {
        return new self($this->to, $this->event, $this->text, $this->variables, $dedupeKey, $this->sourceType, $this->sourceId, $this->userId);
    }

    /** The delivery row's dedupe key (`notification_deliveries.dedupe_key`), prefixed so it never meets another channel's. */
    public function deliveryKey(): ?string
    {
        return $this->dedupeKey !== null ? 'whatsapp:'.$this->dedupeKey : null;
    }

    /** "+234 ••• 4567": enough to recognise the number in a log or a page. */
    public function maskedTo(): string
    {
        return strlen($this->to) > 8 ? substr($this->to, 0, 4).' ••• '.substr($this->to, -4) : '•••';
    }
}
