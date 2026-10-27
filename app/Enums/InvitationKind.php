<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * What an invitation offers (`invitations.kind`, ARCHITECTURE.md § 6.1).
 * `user`: a person joins the organization with roles (members P01-04,
 * tenants P03-01b); only these are opened at /invitations/{token} and
 * listed under Settings → Members. `org_billing_transfer` (P02-04): the
 * link of an offer to hand the organization's GoProperty bill to
 * `target_organization_id`; written only by the SaasBilling module's
 * BillingTransferService and opened at /billing-transfers/{token}.
 */
enum InvitationKind: string
{
    use HasValues;

    case User = 'user';
    case OrgBillingTransfer = 'org_billing_transfer';
}
