<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Statements;

use Carbon\CarbonImmutable;

/**
 * One calendar month of an owner statement, in Lagos time (P08-05;
 * AGENT.md: periods and dates in Africa/Lagos): "2027-01" in URLs,
 * "January 2027" for people. A payment confirmed at 23:30 on 31 January
 * Lagos time counts in January, whatever the server's clock zone.
 */
final readonly class StatementMonth
{
    public const string TIMEZONE = 'Africa/Lagos';

    /** How many months the statement lists show, the current one included. */
    public const int LISTED = 12;

    private function __construct(public int $year, public int $month) {}

    public static function of(int $year, int $month): self
    {
        return new self($year, $month);
    }

    /** "2027-01", or null when it is not a month from 2000 up to the current one. */
    public static function fromKey(string $key): ?self
    {
        if (preg_match('/^(\d{4})-(\d{2})$/D', $key, $parts) !== 1) {
            return null;
        }

        $month = new self((int) $parts[1], (int) $parts[2]);

        return $month->month >= 1 && $month->month <= 12 && $month->year >= 2000 && ! $month->isFuture() ? $month : null;
    }

    /** The month now in Lagos. */
    public static function current(): self
    {
        $now = CarbonImmutable::now(self::TIMEZONE);

        return new self($now->year, $now->month);
    }

    /** The month a moment falls in, in Lagos. */
    public static function containing(CarbonImmutable $moment): self
    {
        $local = $moment->setTimezone(self::TIMEZONE);

        return new self($local->year, $local->month);
    }

    /** The month of a settlement row's `month` (its first day). */
    public static function ofDate(CarbonImmutable $firstDay): self
    {
        return new self($firstDay->year, $firstDay->month);
    }

    /**
     * The $count months up to this one, newest first.
     *
     * @return list<self>
     */
    public function lastMonths(int $count = self::LISTED): array
    {
        $months = [];
        $month = $this;

        for ($i = 0; $i < $count; $i++) {
            $months[] = $month;
            $month = $month->previous();
        }

        return $months;
    }

    public function key(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    public function label(): string
    {
        return $this->start()->format('F Y');
    }

    /** Midnight on the first day, Lagos time (the first moment that counts). */
    public function start(): CarbonImmutable
    {
        return CarbonImmutable::create($this->year, $this->month, 1, 0, 0, 0, self::TIMEZONE) ?? throw new \LogicException('Invalid month.');
    }

    /** Midnight on the next month's first day, Lagos time (the first moment that no longer counts). */
    public function end(): CarbonImmutable
    {
        return $this->start()->addMonthNoOverflow();
    }

    /** The first day as a date (for `spent_on` and the settlement row's `month`). */
    public function firstDay(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->start()->toDateString());
    }

    /** The last day as a date. */
    public function lastDay(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->end()->subDay()->toDateString());
    }

    public function previous(): self
    {
        $start = $this->start()->subMonthNoOverflow();

        return new self($start->year, $start->month);
    }

    public function next(): self
    {
        $start = $this->end();

        return new self($start->year, $start->month);
    }

    /** Whether the month is over in Lagos (only an ended month can be settled). */
    public function hasEnded(): bool
    {
        return CarbonImmutable::now()->greaterThanOrEqualTo($this->end());
    }

    public function isFuture(): bool
    {
        return CarbonImmutable::now()->lessThan($this->start());
    }

    public function equals(self $other): bool
    {
        return $this->year === $other->year && $this->month === $other->month;
    }
}
