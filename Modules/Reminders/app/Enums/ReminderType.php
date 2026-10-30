<?php

declare(strict_types=1);

namespace Modules\Reminders\Enums;

use App\Enums\Concerns\HasValues;

/**
 * The four reminder ladders (ARCHITECTURE.md § 6.7; docs/plans/P05-subtasks.md
 * default 1). Each rung is a day count: before the bill's due date (rent,
 * utilities) or the lease's last day (renewal), after the due date
 * (overdue). The ladders are the same for every organization in P05-01.
 */
enum ReminderType: string
{
    use HasValues;

    case RentDue = 'rent_due';
    case UtilityDue = 'utility_due';
    case BillOverdue = 'bill_overdue';
    case LeaseRenewal = 'lease_renewal';

    /**
     * The rungs, in days: before the date for every type but overdue, after it for overdue.
     *
     * @return non-empty-list<int>
     */
    public function rungs(): array
    {
        return match ($this) {
            self::RentDue => [30, 14, 7, 3, 1, 0],
            self::UtilityDue => [3, 0],
            self::BillOverdue => [1, 7, 14],
            self::LeaseRenewal => [60, 30, 7],
        };
    }

    /**
     * The rungs that also go by SMS (docs/plans/P05-subtasks.md default 14,
     * to save the agency's credits): rent T−7 and T−1, utilities T−0,
     * overdue +1 and +7. Renewal alerts go to staff: never by SMS.
     *
     * @return list<int>
     */
    public function smsRungs(): array
    {
        return match ($this) {
            self::RentDue => [7, 1],
            self::UtilityDue => [0],
            self::BillOverdue => [1, 7],
            self::LeaseRenewal => [],
        };
    }

    /** Whether the rungs count days after the date (overdue) rather than before it. */
    public function countsAfter(): bool
    {
        return $this === self::BillOverdue;
    }

    /**
     * The signed offset stored on the reminder (`reminders.offset_days`):
     * negative before the date (T−7 is −7), positive after it (+1).
     */
    public function offset(int $rung): int
    {
        return $this->countsAfter() ? $rung : -$rung;
    }

    /** Whether the tenant is told (bills), or staff (renewals). */
    public function toTenant(): bool
    {
        return $this !== self::LeaseRenewal;
    }

    public function label(): string
    {
        return match ($this) {
            self::RentDue => 'Rent due',
            self::UtilityDue => 'Utility due',
            self::BillOverdue => 'Overdue bill',
            self::LeaseRenewal => 'Lease renewal',
        };
    }
}
