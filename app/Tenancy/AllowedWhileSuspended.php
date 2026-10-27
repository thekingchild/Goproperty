<?php

declare(strict_types=1);

namespace App\Tenancy;

use Attribute;

/**
 * Marks a controller action or Livewire method that may run while its
 * organization is suspended or cancelled although it is not a GET: an
 * export, a download, paying the GoProperty subscription. Only for actions
 * that change nothing in the organization's data, or that the docs allow
 * during suspension (BILLING_PAYMENTS.md § 2, ONBOARDING_COMPLIANCE.md § 3).
 * On a policy method it lets that write ability pass the Gate::before
 * check in a suspended organization (a tenant's payment proof, P03-09);
 * the policy still decides. Everything else is refused by
 * OrganizationWriteGuard.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class AllowedWhileSuspended {}
