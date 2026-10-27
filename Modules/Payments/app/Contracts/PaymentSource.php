<?php

declare(strict_types=1);

namespace Modules\Payments\Contracts;

use App\Enums\Concerns\HasValues;

/**
 * Where a payment's record came from (`payments.source`, DATABASE_SCHEMA.md
 * § 4): a member typed it, a tenant's proof (P03-09), a bank statement line
 * matched to a bill (P03-07b), the organization's own Paystack webhook
 * (P03-08). Public for Contracts\PaymentRecorder.
 */
enum PaymentSource: string
{
    use HasValues;

    case Manual = 'manual';
    case ProofUpload = 'proof_upload';
    case StatementMatch = 'statement_match';
    case PaystackWebhook = 'paystack_webhook';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Recorded by a member',
            self::ProofUpload => 'Proof from the tenant',
            self::StatementMatch => 'Bank statement',
            self::PaystackWebhook => 'Paystack',
        };
    }
}
