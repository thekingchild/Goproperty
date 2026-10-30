<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Connections;

use App\Models\Organization;

/**
 * WhatsApp is on the Growth plan and above (BILLING_PAYMENTS.md § 1.2):
 * the organization's plan (`organizations.signup_plan_key` until SaaS
 * billing's subscription replaces it, P06) must be one of
 * `services.whatsapp.plans`. On other plans nothing goes by WhatsApp and
 * the settings page says which plan has it; a saved connection is kept.
 */
final class WhatsAppPlan
{
    public function allows(Organization $organization): bool
    {
        $plans = config('services.whatsapp.plans', []);

        return is_array($plans) && $organization->signup_plan_key !== null && in_array($organization->signup_plan_key, $plans, true);
    }
}
