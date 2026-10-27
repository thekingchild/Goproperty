<?php

declare(strict_types=1);

namespace Modules\OnlinePayments\Paystack;

/** The result of trying a secret key against Paystack (PaystackClient::test()). */
enum KeyTest: string
{
    /** Paystack answered the key's request. */
    case Accepted = 'accepted';

    /** Paystack said the key is not valid (401). */
    case Refused = 'refused';

    /** Paystack could not be reached or answered with an error: nothing known. */
    case Unavailable = 'unavailable';
}
