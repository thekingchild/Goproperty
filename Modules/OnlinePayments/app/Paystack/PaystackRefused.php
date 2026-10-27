<?php

declare(strict_types=1);

namespace Modules\OnlinePayments\Paystack;

use RuntimeException;

/** Paystack refused the request with the organization's key (invalid key, a field it does not accept). The message is Paystack's, safe to show the owner. */
final class PaystackRefused extends RuntimeException {}
