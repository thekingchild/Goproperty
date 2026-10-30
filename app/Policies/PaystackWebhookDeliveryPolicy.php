<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PlatformStaff;
use App\Platform\Staff\StaffPermission as P;
use App\Platform\Webhooks\PaystackWebhookDelivery;

/**
 * Console → Operations → Paystack webhooks (P06-03): seen with
 * `operations.view`, `invoices.manage` or `webhooks.replay`; **Replay** with
 * `webhooks.replay` (technical-admin, super-admin), only a delivery whose
 * signature matched. Nothing else changes a delivery.
 */
final class PaystackWebhookDeliveryPolicy
{
    public function viewAny(PlatformStaff $actor): bool
    {
        return $actor->holdsStaffPermission(P::OperationsView)
            || $actor->holdsStaffPermission(P::InvoicesManage)
            || $actor->holdsStaffPermission(P::WebhooksReplay);
    }

    public function view(PlatformStaff $actor, PaystackWebhookDelivery $delivery): bool
    {
        return $this->viewAny($actor);
    }

    public function replay(PlatformStaff $actor, PaystackWebhookDelivery $delivery): bool
    {
        return $delivery->signature_valid && $actor->holdsStaffPermission(P::WebhooksReplay);
    }

    public function create(PlatformStaff $actor): bool
    {
        return false;
    }

    public function update(PlatformStaff $actor, PaystackWebhookDelivery $delivery): bool
    {
        return false;
    }

    public function delete(PlatformStaff $actor, PaystackWebhookDelivery $delivery): bool
    {
        return false;
    }
}
