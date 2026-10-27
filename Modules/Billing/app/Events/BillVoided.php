<?php

declare(strict_types=1);

namespace Modules\Billing\Events;

/** A bill was voided with a reason: it asks for nothing any more and accepts no payment. */
final class BillVoided extends BillEvent {}
