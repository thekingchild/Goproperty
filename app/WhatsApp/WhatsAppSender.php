<?php

declare(strict_types=1);

namespace App\WhatsApp;

use App\Models\Organization;

/**
 * Sends a WhatsApp message from an organization's **own** WhatsApp Business
 * account (ARCHITECTURE.md § 6.7: Meta bills the agency; GoProperty never
 * resells messages). Three drivers (`services.whatsapp.driver`):
 *
 * - `cloud` (P05-06): Meta's Cloud API through the organization's own
 *   connection, its approved templates and its event → template mapping
 *   (Modules\WhatsApp, bound from that module's provider). Meta is called
 *   only in production; elsewhere the module's log transport stands in.
 * - `log` (P03 decision 3): LogWhatsAppSender, sends nothing, never
 *   available in production.
 * - `none`: switched off.
 *
 * Never an SMS fallback here: SMS is a separate, paid channel that
 * invitations and sign-in links never use (UX_DESIGN.md § 3.1). Reminders
 * fall back to SMS themselves (WhatsAppFailed, WhatsAppRouting).
 *
 * Callers queue the send (SendWhatsAppMessage) and check available() first
 * with the message's event, so a page never offers WhatsApp to an
 * organization that cannot use it for that message.
 */
interface WhatsAppSender
{
    /**
     * Can this organization send WhatsApp messages now? With $event (e.g.
     * `tenant_invitation`): that message too (the cloud driver needs an
     * active template mapping for it).
     */
    public function available(Organization $organization, ?string $event = null): bool;

    /** @throws WhatsAppUnavailable when it cannot (WhatsAppRefused when Meta refused the message) */
    public function send(Organization $organization, WhatsAppMessage $message): void;

    /**
     * Every try to send the message failed (Meta out of reach): its
     * delivery row, if one is still waiting, is marked failed. Returns that
     * row's id, or null when nothing was waiting (nothing to tell anyone).
     */
    public function gaveUp(Organization $organization, WhatsAppMessage $message): ?int;
}
