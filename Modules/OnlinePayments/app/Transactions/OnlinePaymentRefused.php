<?php

declare(strict_types=1);

namespace Modules\OnlinePayments\Transactions;

use RuntimeException;

/** An online payment that could not be started, with a message for the payer. */
final class OnlinePaymentRefused extends RuntimeException {}
