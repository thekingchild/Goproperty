<?php

declare(strict_types=1);

namespace App\Platform\Filament\Widgets\Operations;

use App\Platform\Deliveries\Health\DeliveryWindow;
use App\Platform\Deliveries\Health\MessagingHealth;
use Filament\Facades\Filament;

/**
 * SMS spend (PLATFORM_CONSOLE.md § 2 "Messaging"; P05-05): SMS GoText took,
 * their pages and GoText's cost as far as it reports it, and the credit
 * ledger's credits used, given back, bought and granted, for the last 24
 * hours and 7 days, across organizations.
 */
final class SmsSpendWidget extends OperationsWidget
{
    protected static ?int $sort = 30;

    protected string $view = 'console.operations.sms-spend';

    public static function canView(): bool
    {
        return MessagingHealth::visibleTo(Filament::auth()->user());
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $health = app(MessagingHealth::class);
        $now = now();

        return ['spends' => array_map(fn (DeliveryWindow $window) => $health->smsSpend($window, $now), DeliveryWindow::cases())];
    }
}
