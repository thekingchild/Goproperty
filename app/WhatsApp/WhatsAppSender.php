<?php

declare(strict_types=1);

namespace App\WhatsApp;

use App\Models\Organization;

/**
 * Sends a WhatsApp message from an organization's **own** WhatsApp Business
 * account (ARCHITECTURE.md § 6.7: Meta bills the agency; GoProperty never
 * resells messages). The real Cloud API driver, the agency's connection
 * and its template mapping come with P05-06; until then the `log` driver
 * (LogWhatsAppSender) stands in and nothing leaves the server (P03
 * decision 3). Never an SMS fallback here: SMS is a separate, paid channel
 * that invitations and sign-in links never use (UX_DESIGN.md § 3.1).
 *
 * Bound in AppServiceProvider from `services.whatsapp.driver`. Callers
 * queue the send (SendWhatsAppMessage) and check available() first, so a
 * page never offers WhatsApp to an organization that cannot use it.
 */
interface WhatsAppSender
{
    /** Can this organization send WhatsApp messages now? */
    public function available(Organization $organization): bool;

    /** @throws WhatsAppUnavailable when it cannot */
    public function send(Organization $organization, WhatsAppMessage $message): void;
}
