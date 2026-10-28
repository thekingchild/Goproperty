<?php

declare(strict_types=1);

namespace Modules\Handovers\Handovers;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Modules\Properties\Contracts\UnitDirectory;
use Modules\SaasBilling\Contracts\SubscriptionQuotes;

/**
 * What a handover does to the receiving organization's GoProperty bill
 * (BILLING_PAYMENTS.md § 7.3): the units the bill would count in the
 * offered buildings (every unit not archived, read through Properties'
 * UnitDirectory in the organization that has them now), and the bill
 * before and after them (SaasBilling's SubscriptionQuotes).
 */
final readonly class HandoverQuotes
{
    public function __construct(
        private OrganizationContext $context,
        private UnitDirectory $units,
        private SubscriptionQuotes $quotes,
    ) {}

    /**
     * Units the bill counts in each building, read in $holder (the
     * organization that has the buildings now).
     *
     * @param  list<int>  $propertyIds
     * @return array<int, int> by building id
     */
    public function unitsIn(Organization $holder, array $propertyIds): array
    {
        return $this->context->run($holder, function () use ($propertyIds): array {
            $counts = [];

            foreach ($propertyIds as $propertyId) {
                $counts[$propertyId] = $this->units->countsForProperty($propertyId)->total();
            }

            return $counts;
        });
    }

    /** The bill change for $receiver taking on $units more units. */
    public function change(Organization $receiver, int $units): BillChange
    {
        $before = $this->quotes->for($receiver);
        $after = $this->quotes->for($receiver, $units);
        $cheapest = $this->quotes->cheapestFor($after->units);

        return new BillChange(
            before: $before,
            after: $after,
            units: $units,
            from: $this->quotes->nextBillingDay(),
            cheaper: $cheapest->plan->key !== $after->plan->key && $cheapest->amount->lessThan($after->amount) ? $cheapest : null,
        );
    }
}
