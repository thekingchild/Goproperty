<?php

declare(strict_types=1);

namespace App\WhatsApp;

use App\Models\Organization;
use Illuminate\Support\Facades\Log;

/**
 * The stand-in WhatsApp driver until P05-06 (P03 decision 3): it sends
 * nothing. Each message is kept in memory for the current process (tests
 * read sent()) and one line goes to the log with the organization, the
 * event and the masked number, never the text (it may hold a one-time
 * link). It claims to be connected everywhere except production, so a
 * real agency is never told a WhatsApp message went out when none did.
 */
final class LogWhatsAppSender implements WhatsAppSender
{
    /** @var list<array{organization_id: int, message: WhatsAppMessage}> */
    private array $sent = [];

    public function available(Organization $organization): bool
    {
        return ! app()->isProduction();
    }

    public function send(Organization $organization, WhatsAppMessage $message): void
    {
        if (! $this->available($organization)) {
            throw new WhatsAppUnavailable('WhatsApp is not connected for this organization.');
        }

        $this->sent[] = ['organization_id' => (int) $organization->getKey(), 'message' => $message];

        Log::info('WhatsApp message not sent (log driver)', [
            'organization_id' => $organization->getKey(),
            'event' => $message->event,
            'to' => $message->maskedTo(),
        ]);
    }

    /** @return list<array{organization_id: int, message: WhatsAppMessage}> */
    public function sent(): array
    {
        return $this->sent;
    }
}
