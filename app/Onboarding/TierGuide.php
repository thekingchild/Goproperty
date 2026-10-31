<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Compliance\TransactionTiers;
use App\Enums\TransactionTier;
use App\Support\Money;
use Illuminate\Contracts\Config\Repository as Config;
use LogicException;

/**
 * The Transaction Tiers as the signup wizard and the Tier application page
 * show them (ONBOARDING_COMPLIANCE.md § 5.2): names, limits and activation
 * from the terms in force today (`transaction_tiers`, Compliance → Tier
 * settings, P06-08), and whom each tier suits from config/tiers.php. It
 * explains and suggests; it enforces nothing.
 */
final readonly class TierGuide
{
    public function __construct(private TransactionTiers $tiers, private Config $config) {}

    /** @return list<TierOption> */
    public function options(): array
    {
        $options = [];

        foreach ($this->tiers->all() as $terms) {
            $suits = $this->config->get("tiers.suits.{$terms->tier->value}");

            $options[] = new TierOption(
                $terms->tier,
                $terms->name,
                $terms->annualLimit,
                $terms->singlePaymentLimit,
                is_string($suits) ? $suits : '',
                $terms->activation(),
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
