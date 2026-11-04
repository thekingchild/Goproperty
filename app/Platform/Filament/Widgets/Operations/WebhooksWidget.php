<?php

declare(strict_types=1);

namespace App\Platform\Filament\Widgets\Operations;

use App\Platform\Filament\Resources\PaystackWebhooks\PaystackWebhookResource;
use App\Platform\Operations\Health\WebhookHealth;
use App\Platform\Staff\StaffPermission as P;
use Carbon\CarbonImmutable;

/**
 * Webhooks (PLATFORM_CONSOLE.md § 2): Paystack deliveries in the last 24
 * hours, last event, failure rate and those awaiting processing, for the
 * platform's own account (Replay on its Paystack webhooks page) and each
 * agency's connected account (shown, not replayed: docs/plans/P08-subtasks.md
 * default 40).
 */
final class WebhooksWidget extends OperationsWidget
{
    protected static ?int $sort = 5;

    protected string $view = 'console.operations.webhooks';

    public static function canView(): bool
    {
        return self::allowed(P::OperationsView, P::WebhooksReplay);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $health = app(WebhookHealth::class);
        $since = CarbonImmutable::now()->subDay();

        return [
            'own' => $health->own($since),
            'agencies' => $health->agencies($since),
            'connected' => $health->connectedAgencies(),
            'ownUrl' => PaystackWebhookResource::canViewAny() ? PaystackWebhookResource::getUrl() : null,
        ];
    }
}
