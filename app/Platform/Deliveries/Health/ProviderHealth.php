<?php

declare(strict_types=1);

namespace App\Platform\Deliveries\Health;

/**
 * One word for how a provider is doing (P05-05; PLATFORM_CONSOLE.md § 2
 * "provider status"), from its failure rate over the last 24 hours:
 * (failed + not delivered) ÷ attempted. Below 5 % is OK (the same 5 % the
 * console's alert rules use for webhooks), 5 % up to 25 % degraded, 25 %
 * or more failing. With no attempt in 24 hours there is nothing to judge
 * ("No traffic").
 */
enum ProviderHealth: string
{
    public const int DEGRADED_FROM_PERCENT = 5;

    public const int FAILING_FROM_PERCENT = 25;

    case Ok = 'ok';
    case Degraded = 'degraded';
    case Failing = 'failing';
    case Quiet = 'quiet';

    public static function judge(int $problems, int $attempted): self
    {
        return match (true) {
            $attempted <= 0 => self::Quiet,
            $problems * 100 >= self::FAILING_FROM_PERCENT * $attempted => self::Failing,
            $problems * 100 >= self::DEGRADED_FROM_PERCENT * $attempted => self::Degraded,
            default => self::Ok,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'OK',
            self::Degraded => 'Degraded',
            self::Failing => 'Failing',
            self::Quiet => 'No traffic',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Ok => 'success',
            self::Degraded => 'warning',
            self::Failing => 'danger',
            self::Quiet => 'gray',
        };
    }
}
