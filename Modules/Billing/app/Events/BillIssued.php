<?php

declare(strict_types=1);

namespace Modules\Billing\Events;

/**
 * A bill was issued (generated from a lease, or made otherwise): Payments
 * applies the tenant's credit to it (P03-10a), Reminders and notifications
 * follow (P05), the invoice PDF is rendered (P03-05c).
 */
final class BillIssued extends BillEvent {}
