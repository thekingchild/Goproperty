<?php

declare(strict_types=1);

namespace Modules\Reminders\Reminders;

use Carbon\CarbonImmutable;
use Modules\Reminders\Enums\ReminderType;

/**
 * One rung of a ladder that falls today for one subject (ReminderPlanner):
 * a bill, a lease's next rent period before its bill exists, or a lease
 * that ends soon. `subject` is the subject part of the dedupe key
 * (`lease:{id}@{period start}`, `bill:{id}`, `lease:{id}`).
 */
final readonly class Rung
{
    public function __construct(
        public ReminderType $type,
        public string $remindableType,
        public int $remindableId,
        public string $subject,
        public CarbonImmutable $dueDate,
        public int $days,
        public ?int $leaseId = null,
        public ?CarbonImmutable $periodStart = null,
    ) {}

    /** The signed offset (`reminders.offset_days`). */
    public function offset(): int
    {
        return $this->type->offset($this->days);
    }

    /** `type|subject|channel|offset|due_date|recipient` (unique per organization). */
    public function dedupeKey(string $channel, string $recipient): string
    {
        return implode('|', [$this->type->value, $this->subject, $channel, (string) $this->offset(), $this->dueDate->toDateString(), $recipient]);
    }
}
