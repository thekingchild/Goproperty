<?php

declare(strict_types=1);

namespace Modules\Leasing\Contracts;

/**
 * An active lease that ends soon, with where its renewal stands (the owner
 * statements' "upcoming renewals", P08-05): `renewal` is the draft renewal
 * waiting to start (LeaseService::renew), if one was made; `autoRenew`
 * says the lease rolls on by itself past its end date.
 */
final readonly class EndingLease
{
    public function __construct(
        public LeaseSummary $lease,
        public bool $autoRenew,
        public ?LeaseSummary $renewal,
    ) {}

    /** "renewal_drafted", "auto_renews" or "not_renewed". */
    public function renewalState(): string
    {
        return match (true) {
            $this->renewal !== null => 'renewal_drafted',
            $this->autoRenew => 'auto_renews',
            default => 'not_renewed',
        };
    }

    public function renewalStateLabel(): string
    {
        return match ($this->renewalState()) {
            'renewal_drafted' => 'Renewal drafted',
            'auto_renews' => 'Rolls on (auto-renew)',
            default => 'Not renewed yet',
        };
    }
}
