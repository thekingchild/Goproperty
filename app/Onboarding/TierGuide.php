<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Enums\TransactionTier;
use App\Support\Money;
use Illuminate\Contracts\Config\Repository as Config;
use LogicException;

/**
 * The Transaction Tiers as the signup wizard shows them, from the launch
 * values in config/tiers.php until the compliance module provides the
 * `transaction_tiers` table (ONBOARDING_COMPLIANCE.md § 5.2). It explains
 * and suggests; it enforces nothing.
 */
final readonly class TierGuide
{
    public function __construct(private Config $config) {}

    /** @return list<TierOption> */
    public function options(): array
    {
        $tiers = $this->config->get('tiers.tiers', []);
        $options = [];

        foreach (TransactionTier::cases() as $tier) {
            $row = is_array($tiers) && is_array($tiers[$tier->value] ?? null) ? $tiers[$tier->value] : [];

            $options[] = new TierOption(
                $tier,
                is_string($row['name'] ?? null) ? $row['name'] : $tier->label(),
                is_int($row['annual_volume_limit_kobo'] ?? null) ? Money::kobo($row['annual_volume_limit_kobo']) : null,
                is_int($row['single_payment_limit_kobo'] ?? null) ? Money::kobo($row['single_payment_limit_kobo']) : null,
                is_string($row['suits'] ?? null) ? $row['suits'] : '',
                is_string($row['activation'] ?? null) ? $row['activation'] : '',
            );
        }

        return $options;
    }

    public function option(TransactionTier $tier): TierOption
    {
        foreach ($this->options() as $option) {
            if ($option->tier === $tier) {
                return $option;
            }
        }

        throw new LogicException("No tier option for {$tier->label()}.");
    }

    /** The lowest tier whose yearly limit covers the stated collections. */
    public function recommend(Money $annual): TransactionTier
    {
        foreach ($this->options() as $option) {
            if ($option->fits($annual)) {
                return $option->tier;
            }
        }

        return TransactionTier::Three;
    }
}
