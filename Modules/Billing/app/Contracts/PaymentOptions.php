<?php

declare(strict_types=1);

namespace Modules\Billing\Contracts;

use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * The online payment options of the pay page (`/pay/{reference}`) and the
 * invoice's "Pay online" link: the slot other modules fill from their
 * providers (P03-08 registers the agency's Paystack button), e.g.
 *
 *     $this->app->callAfterResolving(PaymentOptions::class,
 *         fn (PaymentOptions $options) => $options->register(PaystackOption::class));
 *
 * Without any (all of P03 before P03-08), bills are paid by bank transfer
 * only. A singleton, bound in BillingServiceProvider.
 */
final class PaymentOptions
{
    /** @var list<PaymentOption|class-string<PaymentOption>> */
    private array $options = [];

    public function __construct(private readonly Container $container) {}

    /** @param  PaymentOption|class-string<PaymentOption>  $option */
    public function register(PaymentOption|string $option): void
    {
        $this->options[] = $option;
    }

    /**
     * The options that offer this bill, in the order they were registered.
     *
     * @return list<PaymentOption>
     */
    public function for(PayableBill $bill): array
    {
        $offered = [];

        foreach ($this->options as $index => $option) {
            if (is_string($option)) {
                $option = $this->container->make($option);

                if (! $option instanceof PaymentOption) {
                    throw new LogicException('A payment option implements '.PaymentOption::class.'.');
                }

                $this->options[$index] = $option;
            }

            if ($option->offers($bill)) {
                $offered[] = $option;
            }
        }

        return $offered;
    }
}
