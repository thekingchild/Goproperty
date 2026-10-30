<?php

declare(strict_types=1);

namespace App\Platform\Filament\Widgets\Operations;

use App\Platform\Deliveries\Health\DeliveryWindow;
use App\Platform\Deliveries\Health\MessagingHealth;
use Filament\Facades\Filament;

/**
 * Messaging (PLATFORM_CONSOLE.md § 2; P05-05): SMS and WhatsApp messages of
 * every organization queued in the last 24 hours and 7 days, by what
 * became of them, with the failure rate, each figure linked to the
 * delivery log filtered to it; and what the email provider reported
 * (email sends are not logged yet).
 */
final class MessagingDeliveriesWidget extends OperationsWidget
{
    protected static ?int $sort = 10;

    protected string $view = 'console.operations.messaging-deliveries';

    public static function canView(): bool
    {
        return MessagingHealth::visibleTo(Filament::auth()->user());
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $health = app(MessagingHealth::class);
        $now = now();
        $windows = [];

        foreach (DeliveryWindow::cases() as $window) {
            $windows[] = [
                'window' => $window,
                'channels' => $health->channels($window, $now),
                'email' => $health->email($window, $now),
            ];
        }

        return ['windows' => $windows];
    }
}
