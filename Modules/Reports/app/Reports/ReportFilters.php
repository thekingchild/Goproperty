<?php

declare(strict_types=1);

namespace Modules\Reports\Reports;

use App\Exports\ListFilters;
use Carbon\CarbonImmutable;
use Modules\Reports\Enums\ReportPreset;
use Throwable;

/**
 * What the Reports page shows (P08-01), cleaned: a range of Lagos days
 * (`from`–`to`, both included; at most 24 months, never after today,
 * never before 2000; this month when missing or unreadable), one building
 * or every building the member sees, and how far ahead the renewals look
 * (30, 60 or 90 days). The page, its exports and its PDF all clean their
 * input here, so they always agree. See docs/plans/P08-subtasks.md.
 */
final readonly class ReportFilters
{
    public const string TIMEZONE = 'Africa/Lagos';

    public const int MAX_MONTHS = 24;

    public const string EARLIEST = '2000-01-01';

    /** @var list<int> */
    public const array RENEWAL_WINDOWS = [30, 60, 90];

    public const int DEFAULT_RENEWAL_DAYS = 90;

    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?int $buildingId,
        public int $renewalDays,
        public CarbonImmutable $today,
    ) {}

    /** @param  array<string, mixed>  $input  from, to (Y-m-d), building (id), days */
    public static function fromInput(array $input, ?CarbonImmutable $today = null): self
    {
        $today = ($today ?? self::today())->setTimezone(self::TIMEZONE)->startOfDay();
        [$defaultFrom, $defaultTo] = ReportPreset::ThisMonth->range($today) ?? [$today, $today];
        $from = self::day($input['from'] ?? null);
        $to = self::day($input['to'] ?? null);

        if ($from === null || $to === null) {
            [$from, $to] = [$defaultFrom, $defaultTo];
        }

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        $earliest = CarbonImmutable::parse(self::EARLIEST, self::TIMEZONE);
        $to = $to->greaterThan($today) ? $today : ($to->lessThan($earliest) ? $earliest : $to);
        $from = $from->greaterThan($to) ? $to : ($from->lessThan($earliest) ? $earliest : $from);
        $longest = $to->subMonthsNoOverflow(self::MAX_MONTHS)->addDay();

        if ($from->lessThan($longest)) {
            $from = $longest;
        }

        $days = ListFilters::id($input['days'] ?? null);

        return new self(
            from: $from,
            to: $to,
            buildingId: ListFilters::id($input['building'] ?? null),
            renewalDays: in_array($days, self::RENEWAL_WINDOWS, true) ? $days : self::DEFAULT_RENEWAL_DAYS,
            today: $today,
        );
    }

    /** Today in Lagos, at midnight. */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE)->startOfDay();
    }

    /**
     * The cleaned filters as the list exports carry them.
     *
     * @return array<string, string|int>
     */
    public function toArray(): array
    {
        return array_filter([
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'building' => $this->buildingId,
            'days' => $this->renewalDays,
        ], static fn (string|int|null $value): bool => $value !== null);
    }

    /** The first moment of the range (midnight in Lagos). */
    public function start(): CarbonImmutable
    {
        return $this->from->startOfDay();
    }

    /** The moment just after the range (midnight in Lagos after the last day). */
    public function end(): CarbonImmutable
    {
        return $this->to->addDay()->startOfDay();
    }

    /**
     * The Lagos months the range touches, each clipped to the range.
     *
     * @return list<ReportMonth>
     */
    public function months(): array
    {
        $months = [];
        $cursor = $this->from->startOfMonth();

        while ($cursor->lessThanOrEqualTo($this->to)) {
            $first = $cursor->greaterThan($this->from) ? $cursor : $this->from;
            $last = $cursor->endOfMonth()->startOfDay();
            $months[] = new ReportMonth($cursor->format('Y-m'), $cursor->format('M Y'), $first, $last->greaterThan($this->to) ? $this->to : $last);
            $cursor = $cursor->addMonthNoOverflow()->startOfMonth();
        }

        return $months;
    }

    /** "1 Jan 2027 – 31 Jan 2027". */
    public function label(): string
    {
        return $this->from->format('j M Y').' – '.$this->to->format('j M Y');
    }

    /** The last day the renewals look at. */
    public function renewalsUntil(): CarbonImmutable
    {
        return $this->today->addDays($this->renewalDays);
    }

    public function preset(): ReportPreset
    {
        return ReportPreset::matching($this->from, $this->to, $this->today);
    }

    private static function day(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $value, self::TIMEZONE);
        } catch (Throwable) {
            return null;
        }

        return $day instanceof CarbonImmutable && $day->format('Y-m-d') === $value ? $day : null;
    }
}
