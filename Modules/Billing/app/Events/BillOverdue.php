<?php

declare(strict_types=1);

namespace Modules\Billing\Events;

/** A bill's due date passed while it was unpaid or partly paid (bills:flag-overdue): the reminder ladder starts (P05). */
final class BillOverdue extends BillEvent {}
