<?php

declare(strict_types=1);

namespace Modules\Payments\Contracts;

use App\Enums\Concerns\HasValues;

/**
 * How the tenant paid (`payments.method`, DATABASE_SCHEMA.md § 4). Every
 * method lands in the organization's own account or hands (AGENT.md § 3
 * rule 8): a transfer to its bank account, cash or POS taken by the agency,
 * its own Paystack account. Public for Contracts\PaymentRecorder.
 */
enum PaymentMethod: string
{
    use HasValues;

    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
    case Pos = 'pos';
    case Paystack = 'paystack';

    public function label(): string
    {
        return match ($this) {
            self::BankTransfer => 'Bank transfer',
            self::Cash => 'Cash',
            self::Pos => 'POS',
            self::Paystack => 'Paystack',
        };
    }

    /**
     * What a member may record by hand (Paystack payments come from the
     * organization's Paystack webhook only, P03-08).
     *
     * @return list<self>
     */
    public static function manual(): array
    {
        return [self::BankTransfer, self::Cash, self::Pos];
    }

    /** Cash and POS are recorded with a note (ONBOARDING_COMPLIANCE.md § 4.3). */
    public function needsNote(): bool
    {
        return $this === self::Cash || $this === self::Pos;
    }
}
