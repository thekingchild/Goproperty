<?php

declare(strict_types=1);

namespace Modules\Reminders\Reminders;

use Modules\Reminders\Enums\ReminderChannel;

/**
 * Who a reminder goes to, on which channels (ReminderRecipients): a
 * tenant (their portal account, or the tenant record when they have none)
 * or a member of the organization (renewal alerts). `key` is the
 * recipient part of the dedupe key: `user:{id}` or `tenant:{id}`. `phone`
 * is a verified number (P05-02), else null; `whatsapp` the number the
 * tenant agreed to get the agency's WhatsApp messages at (P05-06), else
 * null; `off` the channels the person turned off for reminders (Settings →
 * Notifications).
 */
final readonly class Recipient
{
    /** @param  list<ReminderChannel>  $off */
    public function __construct(
        public string $key,
        public string $name,
        public ?int $userId,
        public ?int $tenantProfileId,
        public ?string $email,
        public ?string $phone = null,
        public array $off = [],
        public ?string $whatsapp = null,
    ) {}

    /** @return list<ReminderChannel> */
    public function channels(): array
    {
        return array_values(array_filter([
            $this->email !== null && ! in_array(ReminderChannel::Email, $this->off, true) ? ReminderChannel::Email : null,
            $this->userId !== null ? ReminderChannel::InApp : null,
            $this->phone !== null && ! in_array(ReminderChannel::Sms, $this->off, true) ? ReminderChannel::Sms : null,
            $this->whatsapp !== null ? ReminderChannel::WhatsApp : null,
        ]));
    }
}
