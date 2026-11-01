<?php

declare(strict_types=1);

namespace App\Compliance\Monitoring;

use App\Compliance\TransactionTiers;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Modules\Payments\Contracts\PayerType;

/**
 * The Transaction Tier monitoring's numbers (`config/compliance.php` →
 * `monitoring`; defaults 88–92 of docs/plans/P06-subtasks.md) and its
 * Lagos calendar: every day, month and grace end is a Lagos day.
 */
final readonly class MonitoringRules
{
    public function __construct(private Config $config) {}

    public function warningPercent(): int
    {
        return $this->int('warning_percent', 80, 1, 99);
    }

    public function graceDays(): int
    {
        return $this->int('grace_days', 30, 1, 365);
    }

    public function loweredLimitDays(): int
    {
        return $this->int('lowered_limit_days', 60, 1, 365);
    }

    public function maxGraceExtensionDays(): int
    {
        return $this->int('max_grace_extension_days', 90, 1, 365);
    }

    /** MLPPA 2022: a cash payment at or above this is reported by the agency. */
    public function cashThreshold(PayerType $payer): Money
    {
        return $payer === PayerType::Corporate
            ? Money::kobo($this->int('cash_threshold_corporate_kobo', 1_000_000_000, 1))
            : Money::kobo($this->int('cash_threshold_individual_kobo', 500_000_000, 1));
    }

    public function structuringPayments(): int
    {
        return $this->int('structuring_payments', 3, 2, 50);
    }

    public function structuringWindowDays(): int
    {
        return $this->int('structuring_window_days', 14, 1, 90);
    }

    public function structuringNearPercent(): int
    {
        return $this->int('structuring_near_percent', 80, 1, 99);
    }

    public function spikeFactor(): int
    {
        return $this->int('spike_factor', 3, 2, 100);
    }

    public function spikeMonths(): int
    {
        return $this->int('spike_months', 6, 1, 24);
    }

    public function spikeMinimum(): Money
    {
        return Money::kobo($this->int('spike_minimum_kobo', 100_000_000, 0));
    }

    /** The start of a Lagos day: today's, or the given moment's. */
    public static function day(?CarbonImmutable $moment = null): CarbonImmutable
    {
        return CarbonImmutable::parse(TransactionTiers::day($moment), TransactionTiers::TIMEZONE)->startOfDay();
    }

    private function int(string $key, int $default, int $min, ?int $max = null): int
    {
        $value = $this->config->get("compliance.monitoring.{$key}", $default);
        $value = is_numeric($value) ? (int) $value : $default;

        return $max !== null ? max($min, min($max, $value)) : max($min, $value);
    }
}
