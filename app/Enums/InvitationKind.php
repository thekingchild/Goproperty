<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * What an invitation offers (`invitations.kind`, ARCHITECTURE.md § 6.1).
 * `user`: a person joins the organization with roles (members, P01-04);
 * only these are opened at /invitations/{token} and listed under
 * Settings → Members. `org_billing_transfer` (P02-04): the link of an
 * offer to hand the organization's GoProperty bill to
 * `target_organization_id`; written only by the SaasBilling module's
 * BillingTransferService and opened at /billing-transfers/{token}.
 *
 * P03-01b (Tenants module, `tenant_profile_id` set): `tenant` invites a
 * tenant to the portal, valid 14 days, opened at /portal/invitations/{token}
 * (the tenant's profile is linked to a user; no roles and never an
 * `organization_user` row); `sign_in_link` is the agency's one-time sign-in
 * link for a tenant's existing account (`user_id`), valid 24 hours, opened
 * at /portal/sign-in/{token} (P03 decision 4).
 *
 * P03-15 (Properties module, `owner_profile_id` set): `owner` invites a
 * property owner to the owner portal, valid 14 days, roles
 * `[property-owner]`, opened at /owner/invitations/{token} (the owner
 * profile is linked to a user, who joins the organization with the
 * `property-owner` role only: never the workspace). Each kind is opened
 * only by its own page.
 */
enum InvitationKind: string
{
    use HasValues;

    case User = 'user';
    case OrgBillingTransfer = 'org_billing_transfer';
    case Tenant = 'tenant';
    case SignInLink = 'sign_in_link';
    case Owner = 'owner';
}
