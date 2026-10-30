<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Connections;

use App\Models\Organization;
use App\Plans\Feature;
use App\Plans\Features;

/**
 * WhatsApp is on the Growth plan and above (BILLING_PAYMENTS.md § 1.2): the
 * organization's plan, or an override, must include the `whatsapp` feature
 * (App\Plans\Features, P06-01b; before that `services.whatsapp.plans`
 * against the signup plan). On other plans nothing goes by WhatsApp and the
 * settings page says which plan has it; a saved connection is kept.
 */
final readonly class WhatsAppPlan
{
    public function __construct(private Features $features) {}

    public function allows(Organization $organization): bool
    {
        return $this->features->enabled(Feature::WhatsApp, $organization);
    }
}
