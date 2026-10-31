<?php

declare(strict_types=1);

namespace Modules\Leasing\Contracts;

/**
 * How many payments a tenant may make on one rent bill (P03-17;
 * docs/plans/rent-part-payments-2026-10-31.md): the lease's own value, or
 * the organization's default (setting `leasing.max_rent_payments`), or no
 * limit (null) when neither is set. Read by the Payments module when a
 * payment arrives (a tenant's proof that would break it is refused, a
 * payment the agency records over it is flagged) and shown to tenants and
 * members. Answers in the current organization. Bound in
 * LeasingServiceProvider.
 */
interface RentPaymentLimits
{
    /** The organization setting holding the default (null = no limit). */
    public const string SETTING = 'leasing.max_rent_payments';

    /** The largest limit an agency may set (1 = rent is paid in one payment). */
    public const int MAX = 12;

    /** The organization's default, or null for no limit. */
    public function organizationDefault(): ?int;

    /** The limit for rent bills of this lease (its own value, else the default), or null for no limit or an unknown lease. */
    public function forLease(int $leaseId): ?int;
}
