<?php

declare(strict_types=1);

namespace App\Platform\Analytics;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * The System analytics page's date range (P08-02): two Lagos days, both
 * included, from a preset or typed. The latest day is yesterday (the last
 * day the nightly snapshot can have taken); a range is at most MAX_DAYS
 * long and never before 2020; dates out of order are swapped; anything
 * unreadable gives the last 30 days.
 */
final readonly class AnalyticsRange
{
    public const array PRESETS = [
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        '90d' => 'Last 90 days',
        '12m' => 'Last 12 months',
        'year' => 'This year',
        'custom' => 'Dates',
    ];

    public const string DEFAULT = '30d';

    public const int MAX_DAYS = 731;

    private const string EARLIEST = '2020-01-01';

    private function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $preset,
    ) {}

    public static function from(?string $preset, ?string $from = null, ?string $to = null, ?CarbonImmutable $now = null): self
    {
        $latest = ($now ?? CarbonImmutable::now())->setTimezone(MetricsSnapshot::TIMEZONE)->startOfDay()->subDay();
        $preset = is_string($preset) && array_key_exists($preset, self::PRESETS) ? $preset : self::DEFAULT;

        if ($preset === 'custom') {
            $start = self::day($from);
            $end = self::day($to);

            if ($start === null || $end === null) {
                return self::from(self::DEFAULT, now: $now);
            }

            if ($start->greaterThan($end)) {
                [$start, $end] = [$end, $start];
            }

            $end = $end->greaterThan($latest) ? $latest : $end;
            $earliest = CarbonImmutable::parse(self::EARLIEST, MetricsSnapshot::TIMEZONE);
            $start = $start->lessThan($earliest) ? $earliest : $start;
            $start = $start->greaterThan($end) ? $end : $start;

            if ($start->diffInDays($end) >= self::MAX_DAYS) {
                $start = $end->subDays(self::MAX_DAYS - 1);
            }

            return new self($start, $end, 'custom');
        }

        $start = match ($preset) {
            '7d' => $latest->subDays(6),
            '90d' => $latest->subDays(89),
            '12m' => $latest->subYear()->addDay(),
            'year' => $latest->startOfYear(),
            default => $latest->subDays(29),
        };

        return new self($start, $latest, $preset);
    }

    /** @return list<string> every day of the range, YYYY-MM-DD */
    public function days(): array
    {
        $days = [];

        for ($day = $this->from; $day->lessThanOrEqualTo($this->to); $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }

    public function label(): string
    {
        $from = $this->from->format('j M Y');
        $to = $this->to->format('j M Y');

        return $from === $to ? $from : "{$from} – {$to}";
    }

    /** @return array{range: string, from: string, to: string} the query string that brings this range back */
    public function query(): array
    {
        return ['range' => $this->preset, 'from' => $this->from->toDateString(), 'to' => $this->to->toDateString()];
    }

    private static function day(?string $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $value, MetricsSnapshot::TIMEZONE);
        } catch (Throwable) {
            return null;
        }

        return $day instanceof CarbonImmutable && $day->toDateString() === $value ? $day : null;
    }
}
