<?php

declare(strict_types=1);

namespace App\WhatsApp;

use App\Models\Organization;

/** WhatsApp switched off (`services.whatsapp.driver` = `none`): never available. */
final class NoWhatsAppSender implements WhatsAppSender
{
    public function available(Organization $organization, ?string $event = null): bool
    {
        return false;
    }

    public function send(Organization $organization, WhatsAppMessage $message): void
    {
        throw new WhatsAppUnavailable('WhatsApp is switched off.');
    }

    public function gaveUp(Organization $organization, WhatsAppMessage $message): ?int
    {
        return null;
    }
}
