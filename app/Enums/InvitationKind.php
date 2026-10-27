<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * What an invitation offers (`invitations.kind`, ARCHITECTURE.md § 6.1).
 * `user`: a person joins the organization with roles (members P01-04,
 * tenants P03-01b). The org-to-org billing transfer (`org_billing_transfer`,
 * P02-04) is added with BillingTransferService.
 */
enum InvitationKind: string
{
    use HasValues;

    case User = 'user';
}
