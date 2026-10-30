<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

/** No WhatsApp module: reminders never go by WhatsApp; SMS stays where it was. */
final class NoWhatsAppRouting implements WhatsAppRouting
{
    public function order(string $event): array
    {
        return ['sms'];
    }

    public function usable(string $event): bool
    {
        return false;
    }

    public function optedIn(array $tenantIds): array
    {
        return [];
    }
}
