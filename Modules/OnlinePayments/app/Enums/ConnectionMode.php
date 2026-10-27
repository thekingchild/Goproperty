<?php

declare(strict_types=1);

namespace Modules\OnlinePayments\Enums;

/** Paystack's two key sets (`pk_test_…`/`sk_test_…`, `pk_live_…`/`sk_live_…`); Paystack calls it the transaction's `domain`. */
enum ConnectionMode: string
{
    case Test = 'test';
    case Live = 'live';

    public function label(): string
    {
        return match ($this) {
            self::Test => 'Test mode',
            self::Live => 'Live',
        };
    }
}
