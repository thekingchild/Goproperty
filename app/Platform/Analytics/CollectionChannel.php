<?php

declare(strict_types=1);

namespace App\Platform\Analytics;

/**
 * How agencies' tenant payments were recorded (PLATFORM_CONSOLE.md § 3
 * "Collections (recorded, not handled by GoProperty)"): the dimension of
 * the `collections` metrics. GoProperty never touches this money (AGENT.md
 * § 3 rule 8); these are the confirmed records in `payments`, by where the
 * record came from (`payments.source`) and, for those typed by a member,
 * how the tenant paid (`payments.method`). Imported history is left out:
 * it was collected before GoProperty.
 */
enum CollectionChannel: string
{
    case BankMatch = 'bank_match';
    case Paystack = 'paystack';
    case Proof = 'proof';
    case Cash = 'cash';
    case Transfer = 'transfer';

    public static function of(string $source, string $method): ?self
    {
        return match ($source) {
            'statement_match' => self::BankMatch,
            'paystack_webhook' => self::Paystack,
            'proof_upload' => self::Proof,
            'manual' => in_array($method, ['cash', 'pos'], true) ? self::Cash : ($method === 'paystack' ? self::Paystack : self::Transfer),
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::BankMatch => 'Bank statement match',
            self::Paystack => 'Paystack (agency\'s own)',
            self::Proof => 'Proof from the tenant',
            self::Cash => 'Cash or POS',
            self::Transfer => 'Transfer recorded by a member',
        };
    }
}
