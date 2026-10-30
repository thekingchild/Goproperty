<?php

declare(strict_types=1);

namespace App\Platform\Webhooks;

use App\Models\PlatformStaff;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\SaasBilling\Contracts\PlatformWebhookReplays;

/**
 * Replays a delivery to GoProperty's own Paystack webhook from the console
 * (P06-03; PLATFORM_CONSOLE.md § 3): checks `webhooks.replay` again, hands
 * the delivery to SaasBilling (which asks Paystack and settles a payment at
 * most once) and writes one audit entry (log `webhooks`, the staff member
 * as causer).
 */
final readonly class WebhookReplay
{
    public const string LOG = 'webhooks';

    /** @throws AuthorizationException */
    public function replay(PlatformStaff $staff, PaystackWebhookDelivery $delivery): bool
    {
        if (! $staff->can('replay', $delivery)) {
            throw new AuthorizationException;
        }

        $replayed = app()->bound(PlatformWebhookReplays::class) && app(PlatformWebhookReplays::class)->replay($delivery->id);

        if ($replayed) {
            activity(self::LOG)
                ->event('webhook.replayed')
                ->performedOn($delivery)
                ->causedBy($staff)
                ->withProperties(['provider' => 'paystack', 'event' => $delivery->event, 'reference' => $delivery->reference, 'outcome_before' => $delivery->outcome])
                ->log('Paystack webhook replayed');
        }

        return $replayed;
    }
}
