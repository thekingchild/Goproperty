<?php

declare(strict_types=1);

namespace Modules\LateFees\Charges;

use App\Models\Organization;
use App\Settings\SettingsStore;
use App\Support\Money;

/**
 * An agency's late-fee settings (PL-02, docs/plans/late-fees-2026-11-11.md
 * defaults 2 and 3): off until switched on; then a percentage (basis
 * points) of what is unpaid on a rent bill, once its due date is the grace
 * days behind, with an optional cap. Defined in LateFeesServiceProvider.
 */
final readonly class LateFeeSettings
{
    public const string ENABLED = 'late_fees.enabled';

    public const string RATE_BPS = 'late_fees.rate_bps';

    public const string GRACE_DAYS = 'late_fees.grace_days';

    public const string CAP_KOBO = 'late_fees.cap_kobo';

    public const int DEFAULT_RATE_BPS = 500;

    public const int DEFAULT_GRACE_DAYS = 7;

    /** 0.01 % to 50 %. */
    public const int MIN_RATE_BPS = 1;

    public const int MAX_RATE_BPS = 5000;

    public const int MAX_GRACE_DAYS = 90;

    /** ₦1 to ₦100,000,000. */
    public const int MIN_CAP_KOBO = 100;

    public const int MAX_CAP_KOBO = 10_000_000_000;

    public function __construct(
        public bool $enabled,
        public int $rateBps,
        public int $graceDays,
        public ?Money $cap,
    ) {}

    public static function of(Organization $organization): self
    {
        $store = app(SettingsStore::class);
        $rate = $store->get(self::RATE_BPS, $organization);
        $grace = $store->get(self::GRACE_DAYS, $organization);
        $cap = $store->get(self::CAP_KOBO, $organization);

        return new self(
            enabled: $store->get(self::ENABLED, $organization) === true,
            rateBps: is_int($rate) ? $rate : self::DEFAULT_RATE_BPS,
            graceDays: is_int($grace) ? $grace : self::DEFAULT_GRACE_DAYS,
            cap: is_int($cap) && $cap > 0 ? Money::kobo($cap) : null,
        );
    }

    /** The fee on $unpaid: the rate, rounded to the kobo, at most the cap. */
    public function feeOn(Money $unpaid): Money
    {
        $fee = $unpaid->percentage($this->rateBps);

        return $this->cap !== null ? Money::min($fee, $this->cap) : $fee;
    }

    /** "5 %", "2.5 %": the rate as the agency typed it. */
    public function rateLabel(): string
    {
        return rtrim(rtrim(number_format($this->rateBps / 100, 2, '.', ''), '0'), '.').' %';
    }
}
