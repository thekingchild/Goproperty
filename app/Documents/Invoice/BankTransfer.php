<?php

declare(strict_types=1);

namespace App\Documents\Invoice;

/**
 * The organization's account the invoice asks the tenant to pay into
 * (BRANDING_DOCUMENTS.md § 2 item 6; ONBOARDING_COMPLIANCE.md § 4.1: the
 * organization's own account, never GoProperty's).
 */
final readonly class BankTransfer
{
    public function __construct(
        public string $bank,
        public string $accountName,
        public string $accountNumber,
    ) {}
}
