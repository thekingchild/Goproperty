<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Meta;

enum CheckResult: string
{
    /** Meta accepted the token for the phone number. */
    case Accepted = 'accepted';

    /** Meta refused the token or does not know the phone number id. */
    case Refused = 'refused';

    /** Meta could not be reached, or answered oddly. */
    case Unavailable = 'unavailable';
}
