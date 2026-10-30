<?php

declare(strict_types=1);

namespace App\Platform\Filament\Widgets\Operations;

use App\Platform\Deliveries\Health\MessagingHealth;
use Filament\Facades\Filament;

/**
 * Provider status (PLATFORM_CONSOLE.md § 2 "Messaging"; P05-05): each
 * provider's health word from its last 24 hours, its last message taken
 * and last failure (error with numbers masked), how delivery reports
 * arrive; agencies' WhatsApp connections by status; and the email
 * provider's bounce webhook.
 */
final class MessagingProvidersWidget extends OperationsWidget
{
    protected static ?int $sort = 20;

    protected string $view = 'console.operations.messaging-providers';

    public static function canView(): bool
    {
        return MessagingHealth::visibleTo(Filament::auth()->user());
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $health = app(MessagingHealth::class);

        return [
            'providers' => $health->providers(now()),
            'connections' => $health->whatsAppConnections(),
            'mailer' => (string) config('mail.default'),
            'lastEmailWebhookAt' => $health->lastEmailWebhookAt(),
        ];
    }
}
