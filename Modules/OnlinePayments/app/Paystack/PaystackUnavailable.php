<?php

declare(strict_types=1);

namespace Modules\OnlinePayments\Paystack;

use RuntimeException;

/** Paystack could not be reached, or answered with an error or something unreadable: nothing may be concluded (try again later). */
final class PaystackUnavailable extends RuntimeException {}
