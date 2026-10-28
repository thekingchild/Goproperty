<?php

declare(strict_types=1);

namespace Modules\Tickets\Tickets;

use App\Settings\SettingsStore;

/**
 * The organization's ticket settings (docs/plans/P04-subtasks.md defaults
 * 1 and 2), defined in TicketsServiceProvider and changed on Settings →
 * Maintenance tickets (Livewire\TicketSettingsPage): how many days after a
 * resolution the tenant may still reopen a ticket (`tickets.reopen_days`,
 * 1–90, default 14) and after how many days a resolved ticket the tenant
 * does not answer closes itself (`tickets.auto_close_days`, 1–30,
 * default 7). Read for the current organization.
 */
final readonly class TicketSettings
{
    public const string REOPEN_DAYS = 'tickets.reopen_days';

    public const int DEFAULT_REOPEN_DAYS = 14;

    public const int MAX_REOPEN_DAYS = 90;

    public const string AUTO_CLOSE_DAYS = 'tickets.auto_close_days';

    public const int DEFAULT_AUTO_CLOSE_DAYS = 7;

    public const int MAX_AUTO_CLOSE_DAYS = 30;

    public function __construct(private SettingsStore $settings) {}

    public function reopenDays(): int
    {
        $days = $this->settings->get(self::REOPEN_DAYS);

        return is_int($days) && $days > 0 ? $days : self::DEFAULT_REOPEN_DAYS;
    }

    public function autoCloseDays(): int
    {
        $days = $this->settings->get(self::AUTO_CLOSE_DAYS);

        return is_int($days) && $days > 0 ? $days : self::DEFAULT_AUTO_CLOSE_DAYS;
    }
}
