<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Enums;

use App\Enums\Concerns\HasValues;

/** What a platform invoice is for: an SMS credit bundle (P05-03); subscriptions come in P06-02. */
enum InvoiceKind: string
{
    use HasValues;

    case SmsCredits = 'sms_credits';
}
