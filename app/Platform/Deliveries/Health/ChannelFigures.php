<?php

declare(strict_types=1);

namespace App\Platform\Deliveries\Health;

/**
 * One channel's deliveries queued in one period, counted by status
 * (P05-05). Every figure is a sum of these counts over a DeliveryOutcome's
 * statuses, so the page and the delivery log's filters cannot disagree.
 */
final readonly class ChannelFigures
{
    /** @param  array<string, int>  $byStatus  status value => deliveries */
    public function __construct(
        public string $channel,
        public DeliveryWindow $window,
        public array $byStatus,
    ) {}

    public function count(DeliveryOutcome $outcome): int
    {
        $count = 0;

        foreach ($outcome->statusValues() as $status) {
            $count += $this->byStatus[$status] ?? 0;
        }

        return $count;
    }

    /** Every delivery queued in the period. */
    public function total(): int
    {
        return array_sum($this->byStatus);
    }

    /** (failed + not delivered) ÷ attempted, e.g. "12.5 %"; null with no attempt. */
    public function failureRate(): ?string
    {
        return self::rate($this->count(DeliveryOutcome::Problems), $this->count(DeliveryOutcome::Attempted));
    }

    public function health(): ProviderHealth
    {
        return ProviderHealth::judge($this->count(DeliveryOutcome::Problems), $this->count(DeliveryOutcome::Attempted));
    }

    /** A share as a percentage with one decimal, rounded half up, in integers only. */
    public static function rate(int $part, int $whole): ?string
    {
        if ($whole <= 0) {
            return null;
        }

        $perMille = intdiv(2000 * $part + $whole, 2 * $whole);

        return intdiv($perMille, 10).'.'.($perMille % 10).' %';
    }

    public static function channelLabel(string $channel): string
    {
        return match ($channel) {
            'sms' => 'SMS',
            'whatsapp' => 'WhatsApp',
            'email' => 'Email',
            default => ucfirst($channel),
        };
    }
}
