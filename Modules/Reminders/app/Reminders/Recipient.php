<?php

declare(strict_types=1);

namespace Modules\Reminders\Reminders;

use Modules\Reminders\Enums\ReminderChannel;

/**
 * Who a reminder goes to, on which channels (ReminderRecipients): a
 * tenant (their portal account, or the tenant record when they have none)
 * or a member of the organization (renewal alerts). `key` is the
 * recipient part of the dedupe key: `user:{id}` or `tenant:{id}`.
 */
final readonly class Recipient
{
    public function __construct(
        public string $key,
        public string $name,
        public ?int $userId,
        public ?int $tenantProfileId,
        public ?string $email,
    ) {}

    /** @return list<ReminderChannel> */
    public function channels(): array
    {
        return array_values(array_filter([
            $this->email !== null ? ReminderChannel::Email : null,
            $this->userId !== null ? ReminderChannel::InApp : null,
        ]));
    }
}
